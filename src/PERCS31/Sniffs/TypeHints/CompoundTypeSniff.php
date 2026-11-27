<?php

declare(strict_types=1);

namespace PERCS31\Sniffs\TypeHints;

use DomainException;
use PERCS31\Helpers\CompoundTypeHelper;
use PERCS31\Helpers\DocTypeHelper;
use PERCS31\Helpers\FixerHelper;
use PERCS31\Helpers\LayoutHelper;
use PERCS31\Helpers\PropertyHookHelper;
use PERCS31\Helpers\TypeSetHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\GetTokensAsString;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use SlevomatCodingStandard\Helpers\Annotation;
use SlevomatCodingStandard\Helpers\AnnotationHelper;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\PhpDocParserHelper;

/**
 * Checks a compound type: its layout, and where `null` goes in a union.
 *
 * The layout: no space around its `|` and `&`, and when it's split across lines, one type, or one union segment, per
 * line, with the symbol at the start of each line.
 *
 * PER Coding Style 3.1, section 2.5, says the union symbol `|` and intersection symbol `&` MUST NOT have a leading or
 * trailing space, and that if a compound type is split across lines:
 * - If it contains only unions or only intersections, each line MUST have a single type.
 * - If it contains both, each line MUST have a single union segment, and all intersections in a segment MUST be on the
 *   same line.
 * - The symbol it's split on MUST be at the start of each line.
 *
 * The spacing: a space before or after a symbol, on its line, is reported as SpaceAroundOperator, and removed. A line
 * break before a symbol is allowed. A symbol at the end of a line is reported as OperatorAtEndOfLine, and the fix moves
 * it to the start of the next line. SlevomatCodingStandard.TypeHints.DNFTypeHintFormat and
 * Universal.Operators.TypeSeparatorSpacing count that line break as a space, so they reject every split type, and
 * aren't used for this.
 *
 * The lines: a type is split if any `|` or `&` outside parentheses starts a line. Then every one of them must start a
 * line, and the fix breaks the line before each that doesn't. A `&` inside a DNF type's parentheses must never start a
 * line, and the fix joins it to the line before.
 *
 * Section 2.5 doesn't say how a split type's lines are indented. Its examples, "correct ways to format compound types",
 * are in parameter lists, where the type starts its line, and each `|` line is level with it. So that's required where
 * a type starts its line, as in a parameter list or a multi-catch. Where other code comes before the type on its line,
 * as in `public A`, `public const A` or `): A`, each `|` line continues that line, so it's indented once past it, as
 * section 6.4 indents any line that continues another. The fix sets the indent.
 *
 * It also covers the `|` between a multi-catch's types, e.g. `catch (A|B $e)`, which is read as a union type's. It
 * replaces PhpCollective.WhiteSpace.CatchTypeSeparatorSpacing there, which reports a type split before the `|`, and
 * can't fix one split after it.
 *
 * It also covers a `set` hook's parameter type, e.g. `set(int|string $value)`. PHP_CodeSniffer doesn't know about
 * hooks, so it gives those symbols and parentheses other tokens, and Slevomat's sniff doesn't see them. So this sniff
 * also checks the space inside a DNF type's parentheses there, as SpaceInsideParentheses, which Slevomat's sniff
 * checks everywhere else.
 *
 * `null`: section 2.5 also says that if one of the ORed conditions is `null`, it MUST be the last item, and that a
 * union of a single simple type with `null` SHOULD be written `?T`.
 *
 * SlevomatCodingStandard.TypeHints.DNFTypeHintFormat checks both, but gets some cases wrong:
 * - It skips the `null` position in any type with an intersection, such as `null|(A&B)`.
 * - For `null|int`, it only reports the missing `?T`, not the position. `?T` is a SHOULD, so with that code set to a
 *   warning, the MUST is lost below warning level.
 * - It doesn't check a typed constant (PHP 8.3), or a `set` hook's parameter, which PHP_CodeSniffer reads as an
 *   expression.
 * - Its `?T` fix deletes `static` from a return type, so `static|null` becomes `?`, which is a parse error.
 *
 * So this sniff makes both checks in every type, and the PERCS31 ruleset doesn't use Slevomat's
 * NullTypeHintNotOnLastPosition or RequiredShortNullable codes. Set nullPosition to `first`, or to an empty value to
 * leave `null` anywhere, and checkShortNullable to false to allow `T|null`.
 *
 * Set sortTypes to sort the types in each union or intersection, as php-cs-fixer's `ordered_types` does, ignoring case
 * and a leading `\`, as NotSorted. `null` isn't sorted, as nullPosition places it. Set checkRedundantTypes to report a
 * repeated type, as DuplicateType, which the fix removes, and, in a union, a type another member covers, such as `true`
 * beside `bool` or `list<int>` beside `array`, as RedundantType, which isn't fixed, as the more specific type may be
 * the one meant. PHP rejects both in a native type, so only doc types are checked for them.
 *
 * Doc comment types, in `@param`, `@return`, `@var` and the rest, follow the same rules as native ones, except the
 * lines of a split type: one split over lines is reported, but not fixed.
 *
 * A type split across lines, or with a comment in it, is reported but not fixed.
 */
class CompoundTypeSniff implements Sniff
{
    public const string CODE_MULTIPLE_TYPES_ON_LINE = 'MultipleTypesOnLine';

    public const string CODE_SPLIT_INTERSECTION = 'SplitIntersection';

    public const string CODE_INDENT = 'Indent';

    public const string CODE_SPACE_AROUND_OPERATOR = 'SpaceAroundOperator';

    public const string CODE_OPERATOR_AT_END_OF_LINE = 'OperatorAtEndOfLine';

    public const string CODE_SPACE_INSIDE_PARENTHESES = 'SpaceInsideParentheses';

    public const string CODE_NULL_POSITION = 'NullPosition';

    public const string CODE_SHORT_NULLABLE = 'ShortNullable';

    public const string CODE_NOT_SORTED = 'NotSorted';

    public const string CODE_DUPLICATE_TYPE = 'DuplicateType';

    public const string CODE_REDUNDANT_TYPE = 'RedundantType';

    public const string CODE_SHOULD_BE_SINGLE_LINE = 'ShouldBeSingleLine';

    /**
     * Whether to report a union of one simple type with `null`, which should be written `?T`.
     */
    public bool $checkShortNullable = true;

    /**
     * Where `null` goes in a union: `first`, `last`, or null or an empty string to allow it anywhere.
     */
    public ?string $nullPosition = 'last';

    /**
     * Whether to sort the types in each union and intersection.
     */
    public bool $sortTypes = false;

    /**
     * Whether to report a repeated type, and a type another member of a union covers, in a doc comment.
     */
    public bool $checkRedundantTypes = false;

    /**
     * Whether to join a type split across lines when the line it then makes fits within maxLineLength. PER allows the
     * split, so this is an extra rule, on in Jumbo.
     */
    public bool $joinSplitTypes = false;

    /**
     * The longest line a split type is joined onto, with joinSplitTypes.
     */
    public int $maxLineLength = 120;

    /**
     * The doc types not fixed, as they're split over lines, by the object ID of the type as parsed.
     *
     * @var array<int, true>
     */
    private array $unfixable = [];

    /**
     * The doc types with a space around a `|` or `&`, by the object ID of the type as parsed.
     *
     * @var array<int, true>
     */
    private array $spaced = [];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [
            T_TYPE_UNION,
            T_TYPE_INTERSECTION,
            T_BITWISE_OR,
            T_BITWISE_AND,
            T_OPEN_PARENTHESIS,
            T_DOC_COMMENT_OPEN_TAG,
        ];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        if (!in_array($this->nullPosition, ['first', 'last', '', null], true)) {
            throw new DomainException(
                "The nullPosition property must be 'first', 'last' or empty; found '{$this->nullPosition}'.",
            );
        }

        $tokens = $phpcsFile->getTokens();
        $code = $tokens[$stackPtr]['code'];
        if ($code === T_DOC_COMMENT_OPEN_TAG) {
            $this->processDocComment($phpcsFile, $stackPtr);
            return;
        }

        if ($code === T_OPEN_PARENTHESIS) {
            if (PropertyHookHelper::isInHookParameterList($phpcsFile, $stackPtr)) {
                self::checkHookTypeParentheses($phpcsFile, $stackPtr);
            }

            return;
        }

        $isHook = $code === T_BITWISE_OR || $code === T_BITWISE_AND;
        if (!CompoundTypeHelper::isTypeSymbol($phpcsFile, $stackPtr)) {
            return;
        }

        self::checkSpacing($phpcsFile, $stackPtr);
        $symbols = CompoundTypeHelper::findSymbols($phpcsFile, $stackPtr, $isHook);

        // Check each type once, from its first symbol.
        if ($symbols[0] !== $stackPtr) {
            return;
        }

        [$typeStart, $typeEnd] = CompoundTypeHelper::findTypeBounds($phpcsFile, $stackPtr, $isHook);
        if (!$this->joinSplitType($phpcsFile, $typeStart, $typeEnd)) {
            self::checkLines($phpcsFile, $typeStart, $typeEnd);
        }

        $this->checkNull($phpcsFile, $typeStart, $typeEnd);
        $this->checkSorting($phpcsFile, $typeStart, $typeEnd);
    }

    /**
     * Check the lines of a compound type split across lines: one type, or one union segment, on each, and each
     * indented as the type's first line requires.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $typeStart The position of the type's first token.
     * @param int $typeEnd The position of the type's last token.
     */
    private static function checkLines(File $phpcsFile, int $typeStart, int $typeEnd): void
    {
        $tokens = $phpcsFile->getTokens();
        [$topLevel, $grouped] = CompoundTypeHelper::splitSymbols($phpcsFile, $typeStart, $typeEnd);

        foreach ($grouped as $symbol) {
            if (LayoutHelper::startsLine($phpcsFile, $symbol)) {
                self::reportSplitIntersection($phpcsFile, $symbol);
            }
        }

        $isSplit = false;
        foreach ($topLevel as $symbol) {
            $isSplit = $isSplit || LayoutHelper::startsLine($phpcsFile, $symbol);
        }

        if (!$isSplit) {
            return;
        }

        $error = $grouped === []
            ? 'A compound type split across lines must have a single type on each line'
            : 'A compound type split across lines must have a single union segment on each line';
        // Each line is level with the type's first line when the type starts that line, as in a parameter list. When
        // other code comes first, as in `public A` or `): A`, each line continues that one, so it's indented once more.
        $indent = LayoutHelper::lineIndent($phpcsFile, $typeStart);
        if (!LayoutHelper::startsLine($phpcsFile, $typeStart)) {
            $indent += LayoutHelper::INDENT;
        }
        foreach ($topLevel as $symbol) {
            if (!LayoutHelper::startsLine($phpcsFile, $symbol)) {
                self::reportMultipleTypesOnLine($phpcsFile, $symbol, $error, $indent);
            } elseif (($tokens[$symbol]['column'] - 1) !== $indent) {
                self::reportIndent($phpcsFile, $symbol, $indent);
            }
        }
    }

    /**
     * With joinSplitTypes, join a type split across lines if the line it then makes fits within maxLineLength.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $typeStart The position of the type's first token.
     * @param int $typeEnd The position of the type's last token.
     * @return bool True if the type is reported for joining, so its lines aren't checked.
     */
    private function joinSplitType(File $phpcsFile, int $typeStart, int $typeEnd): bool
    {
        $tokens = $phpcsFile->getTokens();
        if (
            !$this->joinSplitTypes || $tokens[$typeStart]['line'] === $tokens[$typeEnd]['line']
            || $phpcsFile->findNext(Tokens::COMMENT_TOKENS, $typeStart, ($typeEnd + 1)) !== false
        ) {
            return false;
        }

        // The joined line: what comes before the type on its first line, the type, and the rest of its last line.
        $joined = '';
        for ($i = $typeStart; $i <= $typeEnd; $i++) {
            $joined .= $tokens[$i]['code'] === T_WHITESPACE ? '' : $tokens[$i]['content'];
        }

        $rest = '';
        for ($i = ($typeEnd + 1); isset($tokens[$i]) && $tokens[$i]['line'] === $tokens[$typeEnd]['line']; $i++) {
            $rest .= $tokens[$i]['content'];
        }

        $length = $tokens[$typeStart]['column'] - 1 + mb_strlen($joined) + mb_strlen(rtrim($rest, "\r\n"));
        if ($length > $this->maxLineLength) {
            return false;
        }

        $fix = $phpcsFile->addFixableError(
            'A compound type split across lines fits within %s characters, so it must be written on one line',
            $typeStart,
            self::CODE_SHOULD_BE_SINGLE_LINE,
            [$this->maxLineLength],
        );
        if ($fix) {
            self::replaceType($phpcsFile, $typeStart, $typeEnd, $joined);
        }

        return true;
    }

    /**
     * Check that `null` is where nullPosition puts it in a union, and that a union of one simple type with `null` is
     * written `?T`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the type's first token.
     * @param int $end The position of the type's last token.
     */
    private function checkNull(File $phpcsFile, int $start, int $end): void
    {
        $members = self::findMembers($phpcsFile, $start, $end);
        $nullIndex = self::findNull($phpcsFile, $members);
        if ($nullIndex === null || count($members) < 2) {
            return;
        }

        $fixable = self::isFixable($phpcsFile, $start, $end);
        $type = self::typeText($phpcsFile, $start, $end);
        $isPlaced = $this->isNullPlaced($nullIndex, count($members));
        if (!$isPlaced) {
            $error = 'null must be the %s type in a union; found "%s"';
            $data = [(string) $this->nullPosition, $type];
            $nullPtr = $members[$nullIndex][0];
            if (self::reportFixable($phpcsFile, $error, $nullPtr, self::CODE_NULL_POSITION, $fixable, $data)) {
                self::replaceType($phpcsFile, $start, $end, $this->canonicalText($phpcsFile, $members, true));
            }
        }

        if ($this->checkShortNullable && count($members) === 2) {
            $other = $members[(1 - $nullIndex)];
            if ($other[0] === $other[1]) {
                // Fix it once null is placed, so the two fixes don't clash.
                self::reportShortNullable($phpcsFile, $start, $end, $other[0], $type, $fixable && $isPlaced);
            }
        }
    }

    /**
     * Check that the types in each union and intersection are sorted, if sortTypes is on.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the type's first token.
     * @param int $end The position of the type's last token.
     */
    private function checkSorting(File $phpcsFile, int $start, int $end): void
    {
        if (!$this->sortTypes) {
            return;
        }

        $members = self::findMembers($phpcsFile, $start, $end);
        $sorted = $this->canonicalText($phpcsFile, $members, true);
        $unsorted = $this->canonicalText($phpcsFile, $members, false);
        if ($sorted === $unsorted) {
            return;
        }

        $error = 'The types in a compound type must be in alphabetical order; found "%s", expected "%s"';
        $data = [self::typeText($phpcsFile, $start, $end), $sorted];
        $fixable = self::isFixable($phpcsFile, $start, $end);
        if (self::reportFixable($phpcsFile, $error, $start, self::CODE_NOT_SORTED, $fixable, $data)) {
            self::replaceType($phpcsFile, $start, $end, $sorted);
        }
    }

    /**
     * Check whether `null` is where nullPosition puts it.
     *
     * @param int $nullIndex The index of the `null` member.
     * @param int $count The number of members.
     * @return bool True if it is.
     */
    private function isNullPlaced(int $nullIndex, int $count): bool
    {
        return match ($this->nullPosition) {
            'first' => $nullIndex === 0,
            'last' => $nullIndex === ($count - 1),
            default => true,
        };
    }

    /**
     * Write a native type as it should be: `null` placed, and, with sortTypes on, sorted.
     *
     * @param File $phpcsFile The file being scanned.
     * @param list<array{int, int}> $members The first and last tokens of each union member.
     * @param bool $sort Whether to sort the types, if sortTypes is on.
     * @return string The type's code.
     */
    private function canonicalText(File $phpcsFile, array $members, bool $sort): string
    {
        $sort = $sort && $this->sortTypes;
        $texts = [];
        foreach ($members as [$first, $last]) {
            $text = GetTokensAsString::noEmpties($phpcsFile, $first, $last);
            $texts[] = $sort ? self::sortIntersection($text) : $text;
        }

        $nullIndex = self::findNull($phpcsFile, $members);
        $others = array_values(array_filter($texts, static fn(string $text): bool => strtolower($text) !== 'null'));
        if ($sort) {
            $others = TypeSetHelper::sort($others, static fn(string $text): string => $text);
        }

        return implode('|', $this->placeNull($others, $nullIndex === null ? null : $texts[$nullIndex], $nullIndex));
    }

    /**
     * Sort the types of an intersection, or a DNF type's group, written as code.
     *
     * @param string $text The member's code, such as `B&A` or `(B&A)`.
     * @return string The code, sorted.
     */
    private static function sortIntersection(string $text): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }

        $isGroup = str_starts_with($text, '(') && str_ends_with($text, ')');
        $parts = explode('&', $isGroup ? substr($text, 1, -1) : $text);
        $sorted = implode('&', TypeSetHelper::sort($parts, static fn(string $part): string => $part));

        return $isGroup ? "({$sorted})" : $sorted;
    }

    /**
     * Put `null` among a union's other members, where nullPosition says, or where it was.
     *
     * @template T
     * @param list<T> $others The other members, in order.
     * @param ?T $null The `null` member, or null if there's none.
     * @param ?int $nullIndex Where `null` was.
     * @return list<T> The members.
     */
    private function placeNull(array $others, mixed $null, ?int $nullIndex): array
    {
        if ($null === null || $nullIndex === null) {
            return $others;
        }

        $index = match ($this->nullPosition) {
            'first' => 0,
            'last' => count($others),
            default => min($nullIndex, count($others)),
        };
        array_splice($others, $index, 0, [$null]);

        return $others;
    }

    /**
     * Replace a native type's code.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $start The position of the type's first token.
     * @param int $end The position of the type's last token.
     * @param string $code The new code.
     */
    private static function replaceType(File $phpcsFile, int $start, int $end, string $code): void
    {
        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($start, $code);
        for ($i = ($start + 1); $i <= $end; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Report an error, as fixable if it can be.
     *
     * @param File $phpcsFile The file being scanned.
     * @param string $error The message.
     * @param int $ptr The position to report at.
     * @param string $code The error code.
     * @param bool $fixable Whether it can be fixed.
     * @param list<string> $data The message's values.
     * @return bool True if it's to be fixed now.
     */
    private static function reportFixable(
        File $phpcsFile,
        string $error,
        int $ptr,
        string $code,
        bool $fixable,
        array $data,
    ): bool {
        if (!$fixable) {
            $phpcsFile->addError($error, $ptr, $code, $data);

            return false;
        }

        return $phpcsFile->addFixableError($error, $ptr, $code, $data);
    }

    /**
     * Report a line of a split type that isn't indented as the type's first line requires, and fix its indent.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $symbol The position of the symbol that starts the line.
     * @param int $indent The number of spaces the line should be indented by.
     */
    private static function reportIndent(File $phpcsFile, int $symbol, int $indent): void
    {
        $error
            = 'A line of a compound type split across lines is indented incorrectly; expected %s spaces but found %s';
        $found = $phpcsFile->getTokens()[$symbol]['column'] - 1;
        FixerHelper::reportIndent($phpcsFile, $symbol, $indent, $error, self::CODE_INDENT, [$indent, $found]);
    }

    /**
     * Report a `&` inside parentheses that starts a line, and fix it by joining it to the line before.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $symbol The position of the symbol.
     */
    private static function reportSplitIntersection(File $phpcsFile, int $symbol): void
    {
        $error = 'All intersections in a union segment must be on the same line';
        $prev = $phpcsFile->findPrevious(T_WHITESPACE, ($symbol - 1), null, true);
        $prevContent = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($symbol - 1), null, true);
        if ($prev === false || $prev !== $prevContent) {
            // A comment before the symbol would end up inside the type's line, so leave that to a person.
            $phpcsFile->addError($error, $symbol, self::CODE_SPLIT_INTERSECTION);
            return;
        }

        $fix = $phpcsFile->addFixableError($error, $symbol, self::CODE_SPLIT_INTERSECTION);
        if (!$fix) {
            return;
        }

        FixerHelper::replaceBetween($phpcsFile, $prev, $symbol, '');
    }

    /**
     * Report a `|` or `&` outside parentheses that doesn't start a line, and fix it by breaking the line before it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $symbol The position of the symbol.
     * @param string $error The message.
     * @param int $indent The number of spaces to indent the new line by.
     */
    private static function reportMultipleTypesOnLine(File $phpcsFile, int $symbol, string $error, int $indent): void
    {
        $fix = $phpcsFile->addFixableError($error, $symbol, self::CODE_MULTIPLE_TYPES_ON_LINE);
        if (!$fix) {
            return;
        }

        FixerHelper::breakLineBefore($phpcsFile, $symbol, $indent);
    }

    /**
     * Check the spacing around a type's `|` or `&`: no space either side on its line, and not at the end of a line.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the symbol.
     */
    private static function checkSpacing(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $before = $tokens[($stackPtr - 1)];
        $after = $tokens[($stackPtr + 1)];
        // An indent before the symbol, at the start of its line, isn't a space before it.
        $hasSpaceBefore
            = $before['code'] === T_WHITESPACE && $tokens[($stackPtr - 2)]['line'] === $tokens[$stackPtr]['line'];
        $isLineEnd = $after['code'] === T_WHITESPACE && str_contains($after['content'], $phpcsFile->eolChar);
        $hasSpaceAfter = $after['code'] === T_WHITESPACE && !$isLineEnd;
        $data = [$tokens[$stackPtr]['content']];

        if ($isLineEnd) {
            self::reportLineEnd($phpcsFile, $stackPtr, $hasSpaceBefore);
            return;
        }

        if (!$hasSpaceBefore && !$hasSpaceAfter) {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            'There must be no space around the "%s" in a compound type',
            $stackPtr,
            self::CODE_SPACE_AROUND_OPERATOR,
            $data,
        );
        if (!$fix) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        if ($hasSpaceBefore) {
            $phpcsFile->fixer->replaceToken(($stackPtr - 1), '');
        }

        if ($hasSpaceAfter) {
            $phpcsFile->fixer->replaceToken(($stackPtr + 1), '');
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Report whitespace just inside the parentheses of a DNF type in a `set` hook's parameter, and fix it by removing
     * it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the opening parenthesis.
     */
    private static function checkHookTypeParentheses(File $phpcsFile, int $opener): void
    {
        $tokens = $phpcsFile->getTokens();
        $closer = $tokens[$opener]['parenthesis_closer'] ?? null;
        if (
            !is_int($closer)
            || ($tokens[($opener + 1)]['code'] !== T_WHITESPACE && $tokens[($closer - 1)]['code'] !== T_WHITESPACE)
        ) {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            'There must be no space just inside the parentheses of a compound type',
            $opener,
            self::CODE_SPACE_INSIDE_PARENTHESES,
        );
        if (!$fix) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        for ($i = ($opener + 1); $tokens[$i]['code'] === T_WHITESPACE; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        for ($i = ($closer - 1); $tokens[$i]['code'] === T_WHITESPACE; $i--) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Report a symbol at the end of a line, and fix it by moving it to the start of the next line.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the symbol.
     * @param bool $hasSpaceBefore Whether a space, on the same line, comes before the symbol.
     */
    private static function reportLineEnd(File $phpcsFile, int $stackPtr, bool $hasSpaceBefore): void
    {
        $tokens = $phpcsFile->getTokens();
        $fix = $phpcsFile->addFixableError(
            'A compound type split across lines must have the "%s" at the start of the line, not the end',
            $stackPtr,
            self::CODE_OPERATOR_AT_END_OF_LINE,
            [$tokens[$stackPtr]['content']],
        );
        if (!$fix) {
            return;
        }

        // The symbol moves to just before the first type on the next line, after its indent.
        $next = $phpcsFile->findNext(T_WHITESPACE, ($stackPtr + 1), null, true);
        if ($next === false) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        if ($hasSpaceBefore) {
            $phpcsFile->fixer->replaceToken(($stackPtr - 1), '');
        }

        $phpcsFile->fixer->replaceToken($stackPtr, '');
        $phpcsFile->fixer->addContentBefore($next, $tokens[$stackPtr]['content']);
        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Split a type into the members of its union, outside any DNF parentheses.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the type's first token.
     * @param int $end The position of the type's last token.
     * @return list<array{int, int}> The first and last tokens of each member.
     */
    private static function findMembers(File $phpcsFile, int $start, int $end): array
    {
        $tokens = $phpcsFile->getTokens();
        [$topLevel] = CompoundTypeHelper::splitSymbols($phpcsFile, $start, $end);
        $unions = array_filter(
            $topLevel,
            static fn(int $symbol): bool => in_array($tokens[$symbol]['code'], [T_TYPE_UNION, T_BITWISE_OR], true),
        );

        $members = [];
        $from = $start;
        foreach ([...$unions, ($end + 1)] as $to) {
            $first = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, $from, $to, true);
            $last = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($to - 1), $from, true);
            if ($first !== false && $last !== false) {
                $members[] = [$first, $last];
            }

            $from = $to + 1;
        }

        return $members;
    }

    /**
     * Find which member of a union is `null`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param list<array{int, int}> $members The first and last tokens of each member.
     * @return ?int The member's index, or null if no member is `null`.
     */
    private static function findNull(File $phpcsFile, array $members): ?int
    {
        $tokens = $phpcsFile->getTokens();
        foreach ($members as $index => [$first, $last]) {
            if ($first === $last && strtolower($tokens[$first]['content']) === 'null') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Check whether a type can be fixed: it's on one line, with no comment in it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the type's first token.
     * @param int $end The position of the type's last token.
     * @return bool True if it can.
     */
    private static function isFixable(File $phpcsFile, int $start, int $end): bool
    {
        $tokens = $phpcsFile->getTokens();

        return $tokens[$start]['line'] === $tokens[$end]['line']
            && $phpcsFile->findNext(Tokens::COMMENT_TOKENS, $start, ($end + 1)) === false;
    }

    /**
     * Get a type's text for a message, without whitespace or comments.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the type's first token.
     * @param int $end The position of the type's last token.
     * @return string The type.
     */
    private static function typeText(File $phpcsFile, int $start, int $end): string
    {
        return GetTokensAsString::noEmpties($phpcsFile, $start, $end);
    }

    /**
     * Report a union of one simple type with `null`, and fix it by writing it as `?T`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the type's first token.
     * @param int $end The position of the type's last token.
     * @param int $otherPtr The position of the type that isn't `null`.
     * @param string $type The type's text, for the message.
     * @param bool $fixable Whether to fix it.
     */
    private static function reportShortNullable(
        File $phpcsFile,
        int $start,
        int $end,
        int $otherPtr,
        string $type,
        bool $fixable,
    ): void {
        $shortForm = "?{$phpcsFile->getTokens()[$otherPtr]['content']}";
        $warning = 'A union of one type and null should be written as ?T; found "%s", use "%s"';
        $data = [$type, $shortForm];
        if (!$fixable) {
            $phpcsFile->addWarning($warning, $otherPtr, self::CODE_SHORT_NULLABLE, $data);
            return;
        }

        $fix = $phpcsFile->addFixableWarning($warning, $otherPtr, self::CODE_SHORT_NULLABLE, $data);
        if (!$fix) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($start, $shortForm);
        FixerHelper::blankBetween($phpcsFile, $start, ($end + 1));
        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Check the compound types in a doc comment, and fix them all at once.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the doc comment's `/**`.
     */
    private function processDocComment(File $phpcsFile, int $opener): void
    {
        $parsed = DocCommentHelper::parseDocComment($phpcsFile, $opener);
        if ($parsed === null) {
            return;
        }

        $docTokens = $parsed->getTokens()->getTokens();
        $this->unfixable = [];
        $this->spaced = [];
        $fix = false;
        foreach (AnnotationHelper::getAnnotations($phpcsFile, $opener) as $annotation) {
            if ($annotation->isInvalid()) {
                continue;
            }

            foreach ([UnionTypeNode::class, IntersectionTypeNode::class] as $class) {
                foreach (AnnotationHelper::getAnnotationNodesByType($annotation->getNode(), $class) as $node) {
                    if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
                        $fix = $this->checkDocType($phpcsFile, $annotation, $node, $docTokens) || $fix;
                    }
                }
            }
        }

        if (!$fix) {
            return;
        }

        $content = DocTypeHelper::transformTypes($phpcsFile, $opener, $this->canonicalDocType(...));
        if ($content !== null) {
            DocTypeHelper::replace($phpcsFile, $opener, $content);
        }
    }

    /**
     * Check a union or intersection in a doc comment: the space around its symbols, where `null` goes, `?T`, the
     * order, and repeated and redundant types.
     *
     * @param File $phpcsFile The file being scanned.
     * @param Annotation<\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode> $annotation The tag it's in.
     * @param IntersectionTypeNode|UnionTypeNode $node The type.
     * @param list<array{string, int, int}> $docTokens The doc comment's tokens, as parsed.
     * @return bool True if any report is to be fixed now.
     */
    private function checkDocType(File $phpcsFile, Annotation $annotation, TypeNode $node, array $docTokens): bool
    {
        $ptr = $annotation->getStartPointer();
        $printer = PhpDocParserHelper::getPrinter();
        $whole = $printer->print($node);
        [$isSplit, $isSpaced] = self::findDocSpacing($node, $docTokens);
        $fixable = !$isSplit;
        if ($isSplit) {
            $this->unfixable[spl_object_id($node)] = true;
        }

        $fix = false;
        if ($isSpaced) {
            $this->spaced[spl_object_id($node)] = true;
            $error = 'Expected no space around the | and & in a compound type; found "%s"';
            $data = [self::docTypeSource($node, $docTokens)];
            $fix = self::reportFixable($phpcsFile, $error, $ptr, self::CODE_SPACE_AROUND_OPERATOR, $fixable, $data);
        }

        $texts = array_values(array_map(static fn(TypeNode $type): string => $printer->print($type), $node->types));
        $nullIndex = $node instanceof UnionTypeNode ? self::findDocNull($node->types) : null;
        if ($nullIndex !== null && count($node->types) > 1 && !$this->isNullPlaced($nullIndex, count($node->types))) {
            $error = 'null must be the %s type in a union; found "%s"';
            $data = [(string) $this->nullPosition, $whole];
            $fix = self::reportFixable($phpcsFile, $error, $ptr, self::CODE_NULL_POSITION, $fixable, $data) || $fix;
        }

        $other = $nullIndex === null || count($node->types) !== 2 ? null : $node->types[(1 - $nullIndex)];
        if ($this->checkShortNullable && $other instanceof IdentifierTypeNode) {
            $warning = 'A union of one type and null should be written as ?T; found "%s", use "?%s"';
            $data = [$whole, $printer->print($other)];
            $fix = ($fixable
                ? $phpcsFile->addFixableWarning($warning, $ptr, self::CODE_SHORT_NULLABLE, $data)
                : $phpcsFile->addWarning($warning, $ptr, self::CODE_SHORT_NULLABLE, $data))
                || $fix;
        }

        $others = array_values(array_filter($texts, static fn(string $text): bool => strtolower($text) !== 'null'));
        $sorted = TypeSetHelper::sort($others, static fn(string $text): string => $text);
        if ($this->sortTypes && $sorted !== $others) {
            $error = 'The types in a compound type must be in alphabetical order; found "%s"';
            $fix = self::reportFixable($phpcsFile, $error, $ptr, self::CODE_NOT_SORTED, $fixable, [$whole]) || $fix;
        }

        return $this->checkRedundantDocTypes($phpcsFile, $ptr, $node, $texts, $fixable) || $fix;
    }

    /**
     * Report a repeated type in a doc comment's union or intersection, and a type another member of a union covers.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position to report at.
     * @param IntersectionTypeNode|UnionTypeNode $node The type.
     * @param list<string> $texts Each member's code.
     * @param bool $fixable Whether the type can be fixed.
     * @return bool True if any report is to be fixed now.
     */
    private function checkRedundantDocTypes(
        File $phpcsFile,
        int $ptr,
        TypeNode $node,
        array $texts,
        bool $fixable,
    ): bool {
        if (!$this->checkRedundantTypes) {
            return false;
        }

        $whole = PhpDocParserHelper::getPrinter()->print($node);
        $fix = false;
        $seen = [];
        foreach (array_values($node->types) as $index => $type) {
            $key = TypeSetHelper::sortKey($texts[$index]);
            if (isset($seen[$key])) {
                $error = 'The type "%s" is repeated in "%s"';
                $data = [$texts[$index], $whole];
                $fix
                    = self::reportFixable($phpcsFile, $error, $ptr, self::CODE_DUPLICATE_TYPE, $fixable, $data) || $fix;
                continue;
            }

            $seen[$key] = true;
            $members = array_values($node->types);
            $covering = $node instanceof UnionTypeNode ? TypeSetHelper::findCovering($type, $members) : null;
            if (
                $covering !== null
                && TypeSetHelper::sortKey($texts[$index]) !== TypeSetHelper::sortKey((string) $covering)
            ) {
                $error = 'The type "%s" is redundant in "%s", as "%s" covers it';
                $data = [$texts[$index], $whole, PhpDocParserHelper::getPrinter()->print($covering)];
                $phpcsFile->addError($error, $ptr, self::CODE_REDUNDANT_TYPE, $data);
            }
        }

        return $fix;
    }

    /**
     * Find whether a doc type's members are split over lines, or have a space around a symbol between them.
     *
     * @param IntersectionTypeNode|UnionTypeNode $node The type.
     * @param list<array{string, int, int}> $docTokens The doc comment's tokens, as parsed.
     * @return array{bool, bool} Whether it's split, and whether it has a space around a symbol on a line.
     */
    private static function findDocSpacing(TypeNode $node, array $docTokens): array
    {
        $isSplit = false;
        $isSpaced = false;
        $count = count($node->types);
        for ($index = 0; ($index + 1) < $count; $index++) {
            $from = $node->types[$index]->getAttribute(Attribute::END_INDEX);
            $to = $node->types[($index + 1)]->getAttribute(Attribute::START_INDEX);
            if (!is_int($from) || !is_int($to)) {
                continue;
            }

            for ($i = ($from + 1); $i < $to; $i++) {
                $type = $docTokens[$i][Lexer::TYPE_OFFSET] ?? null;
                $isSplit = $isSplit || $type === Lexer::TOKEN_PHPDOC_EOL;
                $isSpaced = $isSpaced || $type === Lexer::TOKEN_HORIZONTAL_WS;
            }
        }

        return [$isSplit, $isSpaced && !$isSplit];
    }

    /**
     * Get a doc type's code as it's written.
     *
     * @param TypeNode $node The type.
     * @param list<array{string, int, int}> $docTokens The doc comment's tokens, as parsed.
     * @return string The code.
     */
    private static function docTypeSource(TypeNode $node, array $docTokens): string
    {
        $start = $node->getAttribute(Attribute::START_INDEX);
        $end = $node->getAttribute(Attribute::END_INDEX);
        if (!is_int($start) || !is_int($end)) {
            return PhpDocParserHelper::getPrinter()->print($node);
        }

        $code = '';
        for ($i = $start; $i <= $end; $i++) {
            $code .= $docTokens[$i][Lexer::VALUE_OFFSET] ?? '';
        }

        return $code;
    }

    /**
     * Find which member of a doc type's union is `null`.
     *
     * @param array<TypeNode> $types The members.
     * @return ?int The member's index, or null if none is `null`.
     */
    private static function findDocNull(array $types): ?int
    {
        foreach (array_values($types) as $index => $type) {
            if ($type instanceof IdentifierTypeNode && strtolower($type->name) === 'null') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Rewrite a doc comment's union or intersection as it should be, for the fix. Its members are already rewritten.
     *
     * @param TypeNode $node A copy of the type, whose Attribute::ORIGINAL_NODE is the type as parsed.
     * @return ?TypeNode The new type, or null to keep it.
     */
    private function canonicalDocType(TypeNode $node): ?TypeNode
    {
        $original = $node->getAttribute(Attribute::ORIGINAL_NODE);
        $id = is_object($original) ? spl_object_id($original) : null;
        if (
            !($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) || isset($this->unfixable[$id])
        ) {
            return null;
        }

        $printer = PhpDocParserHelper::getPrinter();
        $types = array_values($node->types);
        if ($this->checkRedundantTypes) {
            $unique = [];
            foreach ($types as $type) {
                $unique[TypeSetHelper::sortKey($printer->print($type))] ??= $type;
            }

            $types = array_values($unique);
        }

        $nullIndex = $node instanceof UnionTypeNode ? self::findDocNull($types) : null;
        $null = $nullIndex === null ? null : $types[$nullIndex];
        $others = array_values(array_filter($types, static fn(TypeNode $type): bool => $type !== $null));
        if ($this->sortTypes) {
            $others = TypeSetHelper::sort($others, static fn(TypeNode $type): string => $printer->print($type));
        }

        $result = $this->placeNull($others, $null, $nullIndex);
        if ($this->checkShortNullable && $null !== null && count($result) === 2) {
            $other = $result[0] === $null ? $result[1] : $result[0];
            if ($other instanceof IdentifierTypeNode) {
                return new NullableTypeNode($other);
            }
        }

        if ($result === array_values($node->types) && !isset($this->spaced[$id])) {
            return null;
        }

        return count($result) === 1
            ? $result[0]
            : ($node instanceof UnionTypeNode ? new UnionTypeNode($result) : new IntersectionTypeNode($result));
    }
}
