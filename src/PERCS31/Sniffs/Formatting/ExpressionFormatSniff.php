<?php

declare(strict_types=1);

namespace PERCS31\Sniffs\Formatting;

use PERCS31\Helpers\ExpressionHelper;
use PERCS31\Helpers\ExpressionNode;
use PERCS31\Helpers\FixerHelper;
use PERCS31\Helpers\LayoutHelper;
use PERCS31\Helpers\StringHelper;
use PERCS31\Helpers\TokenHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires an expression to be split across lines only where it must be, at its loosest operators.
 *
 * PER Coding Style 3.1, section 2.3, says a line over the soft limit SHOULD be split, and section 6.4 lets an
 * expression be split before any operator. So by default, only an expression written on one line that's too long is
 * reported, as TooLong, and split before its loosest operators, which is a judgement call. Reports are warnings, as
 * the limit is soft. With strict on, as in Jumbo, an extra rule, the sniff works out the one layout an expression
 * should have, and reports it if it's written any other way, as ShouldBeSingleLine or IncorrectSplit:
 * - If the expression fits within the line length on one line, it's written on one line.
 * - Otherwise, it's split before the operators that bind most loosely, outside any grouping parentheses, such as the
 *   `||` in `$a && $b || $c`. Each line holds as many operands as fit, so a line only breaks before the operator whose
 *   operand wouldn't fit on it. Each new line is indented once past the line the expression starts on, or lined up
 *   with the first line of a control structure's condition, when that starts its own line.
 * - An operand too long for a line of its own is split the same way, at its own loosest operators. One without
 *   parentheses keeps the same indent, as `$b` then `&& $c` does in `$a` then `|| $b` then `&& $c`. One in grouping
 *   parentheses keeps them with its contents, and its new lines are indented once past the line with its `(`.
 *
 * A `key => value` pair, in an array or a `match` arm with one condition, is one expression, but its `=>` isn't split
 * at first. A pair that doesn't fit on one line is laid out by the first of these that applies:
 * - A value with operators of its own is split at them, with `key =>` kept on the first line. A string expression
 *   that becomes one string counts as one operand.
 * - A value that fits on a line of its own goes there, starting with the `=>`, indented once.
 * - Otherwise, the value stays after `key =>`, and a string is split.
 * A key too long for its line is split as any string is. There's never a line break after a `=>`.
 *
 * An expression fits on its line if its own text, and any punctuation straight after it, such as a `;`, `,` or `)`,
 * fit within the line length. What follows that, such as an array value after its key or a comment, has a place of
 * its own to break at, so it's never a reason to split the expression, or a string in it.
 *
 * An expression ends at anything that isn't part of it: a `;`, `,`, any other `=>`, assignment, ternary `?` or `:`, or
 * the brackets of a call, array or control structure. Each argument of a call is its own expression. An operand isn't
 * changed inside, so the sniff skips an expression with a line break inside an operand, such as a multi-line call or
 * closure, and one with a comment, a heredoc, a nowdoc, a multi-line string or an arrow function. It also skips a
 * `case` condition. It never splits a ternary's condition or value, which share their lines with the rest of the
 * ternary, or an expression written on one line that's too long, unless it's a whole statement, a value after an
 * assignment, `return` or the like, a `key => value` pair that starts its line, or a control structure's condition on
 * its own line, where a split can't clash with the layout of the code around it.
 *
 * It covers every binary operator except the assignment operators and `and`, `or` and `xor`, which bind more loosely
 * than assignment. It only decides where the lines break. An operator at the end of a line counts as a break at it,
 * and PERCS31.Operators.OperatorPlacement reports it there, and checks each line's indent. The fix puts each operator
 * at the start of its line, as that sniff does.
 */
class ExpressionFormatSniff implements Sniff
{
    public const string CODE_SHOULD_BE_SINGLE_LINE = 'ShouldBeSingleLine';

    public const string CODE_INCORRECT_SPLIT = 'IncorrectSplit';

    public const string CODE_TOO_LONG = 'TooLong';

    /**
     * A call or array on one line, from findCallLayout().
     */
    private const string FLAT = 'flat';

    /**
     * A call or array chopped down, one argument per line, from findCallLayout().
     */
    private const string CHOPPED = 'chopped';

    /**
     * A call or array split in some other way, from findCallLayout().
     */
    private const string OTHER = 'other';

    public const string CODE_INCORRECT_STRING_FORMAT = 'IncorrectStringFormat';

    public const string CODE_UNNECESSARY_STRING_CONVERSION = 'UnnecessaryStringConversion';

    /**
     * Tokens after which an expression on one line that's too long may be split.
     *
     * @var list<int|string>
     */
    private const array SPLITTABLE_AFTER = [
        T_SEMICOLON,
        T_OPEN_CURLY_BRACKET,
        T_CLOSE_CURLY_BRACKET,
        T_OPEN_TAG,
        T_RETURN,
        T_ECHO,
        T_PRINT,
        T_THROW,
        T_YIELD,
        T_YIELD_FROM,
        T_DOUBLE_ARROW,
        T_MATCH_ARROW,
        T_FN_ARROW,
    ];

    /**
     * Control structures whose header is indented as a condition, as PERCS31.Operators.OperatorPlacement does it.
     *
     * @var list<int|string>
     */
    private const array CONDITION_OWNERS = [T_IF, T_ELSEIF, T_WHILE, T_SWITCH, T_MATCH, T_FOR, T_FOREACH];

    /**
     * Operators that join a condition's parts, which line up with each other in a condition.
     *
     * @var list<int|string>
     */
    private const array BOOLEAN_OPERATORS = [T_BOOLEAN_AND, T_BOOLEAN_OR];

    /**
     * Maximum line length.
     */
    public int $maxLineLength = 120;

    /**
     * Whether to rewrite string expressions as the fewest strings they can be written as, and split long strings.
     */
    public bool $reflowStringExpressions = false;

    /**
     * Whether to require the one layout: an expression that fits on one line, and one split anywhere but before its
     * loosest operators, are reported too. Off, only an expression written on one line that's too long is reported, as
     * PER allows any other layout.
     */
    public bool $strict = false;

    /**
     * The file whose expressions are in $checked.
     */
    private string $checkedFile = '';

    /**
     * The first token of each expression already checked in the current file, as each of its operators is registered.
     *
     * @var array<int, true>
     */
    private array $checked = [];

    /**
     * The string expressions in the current expression that start a line of their own and don't fit on it, which are
     * laid out as strings, with the code each should be, by the position of each one's first token.
     *
     * @var array<int, array{ExpressionNode, string}>
     */
    private array $delegated = [];

    /**
     * How each call or array in the current expression that's split is laid out, by the position of its opening
     * bracket: the indent of its arguments' lines, or null if one argument is hugged, and the hugged argument's index.
     *
     * @var array<int, array{?int, ?int}>
     */
    private array $callLayouts = [];

    /**
     * The indent of each `match` arm's conditions in the current expression that are split one per line, by the
     * position of the comma after the first.
     *
     * @var array<int, int>
     */
    private array $listLayouts = [];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [
            ...array_keys(ExpressionHelper::precedences()),
            T_CONSTANT_ENCAPSED_STRING,
            T_DOUBLE_QUOTED_STRING,
            T_ECHO,
            T_PRINT,
            T_OPEN_TAG_WITH_ECHO,
            T_OPEN_PARENTHESIS,
            T_OPEN_SHORT_ARRAY,
            T_MATCH_ARROW,
            T_INLINE_THEN,
        ];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the operator.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $code = $phpcsFile->getTokens()[$stackPtr]['code'];
        if (in_array($code, [T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_QUOTED_STRING], true)) {
            if ($this->reflowStringExpressions) {
                $this->processLiteral($phpcsFile, $stackPtr);
            }

            return;
        }

        if (in_array($code, [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
            if ($this->reflowStringExpressions) {
                self::processConversions($phpcsFile, $stackPtr);
            }

            return;
        }

        $start = self::findStartToken($phpcsFile, $stackPtr);
        if ($start === null) {
            return;
        }

        $stackPtr = $start;

        if ($phpcsFile->getFilename() !== $this->checkedFile) {
            $this->checkedFile = $phpcsFile->getFilename();
            $this->checked = [];
        }

        $region = ExpressionHelper::findOutermostExpression($phpcsFile, $stackPtr, $this->strict);
        if ($region === null || isset($this->checked[$region[0]])) {
            return;
        }

        $this->checked[$region[0]] = true;
        $expression = ExpressionHelper::read($phpcsFile, $stackPtr, $region);
        if ($expression === null) {
            return;
        }

        [$root, $before, $after] = $expression;
        $start = $root->start;
        $end = $root->end;
        $tokens = $phpcsFile->getTokens();
        $column = $tokens[$start]['column'] - 1;
        $lineIndent = LayoutHelper::lineIndent($phpcsFile, $start);
        $conditionOpener = self::findConditionOpener($phpcsFile, $start);
        $isCondition = $conditionOpener !== null && LayoutHelper::startsLine($phpcsFile, $start);
        $continuationIndent = $lineIndent + LayoutHelper::INDENT;
        if ($conditionOpener !== null) {
            $continuationIndent = LayoutHelper::lineIndent($phpcsFile, $conditionOpener) + LayoutHelper::INDENT;
        }

        if ($this->reflowStringExpressions && $this->processStrings($phpcsFile, $root, $before, $after)) {
            return;
        }

        $breaks = [];
        $trailing = LayoutHelper::attachedLength($phpcsFile, $end);
        $inCondition = $conditionOpener !== null;
        $this->delegated = [];
        $this->callLayouts = [];
        $this->listLayouts = [];
        $this->layout($phpcsFile, $root, $column, $lineIndent, $continuationIndent, $trailing, $inCondition, $breaks);
        $current = [];
        $misplaced = false;
        $this->findCurrentBreaks($phpcsFile, $root, $current, $misplaced);
        ksort($current);
        ksort($breaks);
        $isLaidOut = !$misplaced && array_keys($current) === array_keys($breaks);

        // A ternary's condition or value shares its lines with the rest of the ternary, so it's never split. An
        // expression on one line is only split where that can't clash with the layout around it. Neither is checked,
        // and nor are the strings laid out to fit the layout.
        $isLeftAlone = !$isLaidOut
            && (($breaks !== [] && self::isInTernary($phpcsFile, $before, $after))
                || ($current === [] && !$misplaced && !$isCondition && !self::isSplittableAfter($phpcsFile, $before)
                    && !self::isPairOnItsOwnLine($phpcsFile, $root)
                    && !self::isElementOnItsOwnLine($phpcsFile, $start, $before))
                || ($breaks === [] && !$this->fitsWithRestOfLine($phpcsFile, $root, $column)));
        if ($isLeftAlone) {
            $this->delegated = [];
        }

        if ($this->reflowStringExpressions) {
            $this->processNestedStrings($phpcsFile, $root);
            foreach ($this->delegated as [$node, $code]) {
                $this->reportString($phpcsFile, $node->start, $node->end, $code);
            }
        }

        // An expression written on one line is too long. Any other difference is only a choice of layout.
        $isTooLong = $current === [] && !$misplaced;
        if ($isLaidOut || $isLeftAlone || (!$isTooLong && !$this->strict)) {
            return;
        }

        $error = 'An expression too long for %s characters must be split outermost first: before its loosest '
            . 'operators, with as many operands on each line as fit, then its calls and arrays.';
        $code = $isTooLong ? self::CODE_TOO_LONG : self::CODE_INCORRECT_SPLIT;
        if ($breaks === []) {
            $error = 'An expression split across lines fits within %s characters, so it must be written on one line.';
            $code = self::CODE_SHOULD_BE_SINGLE_LINE;
        }

        if ($phpcsFile->addFixableWarning($error, $start, $code, [$this->maxLineLength])) {
            $phpcsFile->fixer->beginChangeset();
            $this->applyLayout($phpcsFile, $root, $breaks);
            $phpcsFile->fixer->endChangeset();
        }
    }

    /**
     * Check whether an expression joined onto one line would fit with what follows it on its last line now, such as
     * the rest of a ternary it's the condition of.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $root The expression.
     * @param int $column The number of characters before it on its first line.
     * @return bool True if it would.
     */
    private function fitsWithRestOfLine(File $phpcsFile, ExpressionNode $root, int $column): bool
    {
        $tokens = $phpcsFile->getTokens();
        $rest = 0;
        for ($i = ($root->end + 1); isset($tokens[$i]) && $tokens[$i]['line'] === $tokens[$root->end]['line']; $i++) {
            $rest += mb_strlen(rtrim($tokens[$i]['content'], "\r\n"));
        }

        return ($column + $root->flatLength + $rest) <= $this->maxLineLength;
    }

    /**
     * Find the token to find a registered token's expression from: the token itself, for a binary operator, a call's or
     * array's opening bracket, or a `match` arm's `=>`, or the first value of a ternary, from its `?`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the registered token.
     * @return ?int The position, or null if the token isn't one whose expression is checked.
     */
    private static function findStartToken(File $phpcsFile, int $stackPtr): ?int
    {
        // A call or array is laid out with the expression it's in, which may have no operators, and so is a `match`
        // arm with several conditions, whose `=>` isn't an operator, and a ternary.
        $code = $phpcsFile->getTokens()[$stackPtr]['code'];
        $isChecked = match ($code) {
            T_OPEN_PARENTHESIS, T_OPEN_SHORT_ARRAY => ExpressionHelper::isCallOpener($phpcsFile, $stackPtr),
            T_MATCH_ARROW, T_INLINE_THEN => true,
            default => ExpressionHelper::isBinary($phpcsFile, $stackPtr),
        };
        if (!$isChecked) {
            return null;
        }

        // A ternary is found from its first value, which is widened to the whole ternary, as the `?` itself ends the
        // condition's expression.
        if ($code !== T_INLINE_THEN) {
            return $stackPtr;
        }

        $first = $phpcsFile->findNext(
            [...array_keys(Tokens::EMPTY_TOKENS), T_INLINE_ELSE],
            ($stackPtr + 1),
            null,
            true,
        );

        return $first === false ? null : $first;
    }

    /**
     * Check whether an expression is a ternary's condition or value.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the token that ends the expression before it, or -1.
     * @param int $after The position of the token that ends it after it.
     * @return bool True if it is.
     */
    private static function isInTernary(File $phpcsFile, int $before, int $after): bool
    {
        $tokens = $phpcsFile->getTokens();
        $ternary = [T_INLINE_THEN, T_INLINE_ELSE];

        return in_array($tokens[$after]['code'] ?? null, $ternary, true)
            || ($before >= 0 && in_array($tokens[$before]['code'], $ternary, true));
    }

    /**
     * Check whether an expression on one line can be split, from the token before it: it's a whole statement, or a
     * value after an assignment, `return`, `=>` or the like.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the token that ends the expression before it, or -1.
     * @return bool True if it can.
     */
    private static function isSplittableAfter(File $phpcsFile, int $before): bool
    {
        $tokens = $phpcsFile->getTokens();

        return $before < 0 || in_array($tokens[$before]['code'], self::SPLITTABLE_AFTER, true)
            || isset(Tokens::ASSIGNMENT_TOKENS[$tokens[$before]['code']]);
    }

    /**
     * Check whether a node is a `key => value` pair: an array element, or a `match` arm with one condition.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node.
     * @return bool True if it is.
     */
    private static function isPair(File $phpcsFile, ExpressionNode $node): bool
    {
        return $node->kind === ExpressionNode::LEVEL
            && in_array($phpcsFile->getTokens()[$node->operators[0]]['code'], [T_DOUBLE_ARROW, T_MATCH_ARROW], true);
    }

    /**
     * Check whether an expression is a `key => value` pair that starts its line, such as an element of a multi-line
     * array, which can be split without clashing with the code around it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $root The expression.
     * @return bool True if it is.
     */
    private static function isPairOnItsOwnLine(File $phpcsFile, ExpressionNode $root): bool
    {
        return self::isPair($phpcsFile, $root) && LayoutHelper::startsLine($phpcsFile, $root->start);
    }

    /**
     * Check whether an expression is an argument or array value that starts its own line, in a call or array split
     * over lines, where splitting it can't clash with the layout around it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $before The position of the token that ends the expression before it, or -1.
     * @return bool True if it is.
     */
    private static function isElementOnItsOwnLine(File $phpcsFile, int $start, int $before): bool
    {
        if ($before < 0 || !LayoutHelper::startsLine($phpcsFile, $start)) {
            return false;
        }

        $tokens = $phpcsFile->getTokens();
        $container = $tokens[$before]['code'] === T_COMMA ? TokenHelper::findContainer($phpcsFile, $before) : $before;

        return $container !== null && ExpressionHelper::isCallOpener($phpcsFile, $container);
    }

    /**
     * Check whether a node is a chain of `.` operations.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node.
     * @return bool True if it is.
     */
    private static function isConcatenation(File $phpcsFile, ExpressionNode $node): bool
    {
        return $node->kind === ExpressionNode::LEVEL
            && $phpcsFile->getTokens()[$node->operators[0]]['code'] === T_STRING_CONCAT;
    }

    /**
     * Check whether a node is one string literal, or one in grouping parentheses.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node.
     * @return bool True if it is.
     */
    private function isStringLiteral(File $phpcsFile, ExpressionNode $node): bool
    {
        if ($node->kind === ExpressionNode::GROUP && $node->opener === $node->start) {
            return $this->isStringLiteral($phpcsFile, $node->children[0]);
        }

        $code = $phpcsFile->getTokens()[$node->start]['code'];

        return $node->kind === ExpressionNode::OPERAND && $node->start === $node->end
            && in_array($code, [T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_QUOTED_STRING], true);
    }

    /**
     * Check whether a string expression is laid out as a whole, including splitting its strings, rather than only
     * rewritten on one line, leaving its line breaks to the rest of the sniff.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of its first token.
     * @param int $end The position of its last token.
     * @param int $before The position of the token that ends it before it, or -1.
     * @param int $after The position of the token that ends it after it.
     * @return bool True if it is.
     */
    private static function isStringLaidOut(File $phpcsFile, int $start, int $end, int $before, int $after): bool
    {
        $tokens = $phpcsFile->getTokens();

        return self::findConditionOpener($phpcsFile, $start) === null
            && (!self::isInTernary($phpcsFile, $before, $after)
                || self::isTernaryBranchOnItsOwnLine($phpcsFile, $before))
            && (self::isSplittableAfter($phpcsFile, $before) || LayoutHelper::startsLine($phpcsFile, $start)
                || $tokens[$start]['line'] !== $tokens[$end]['line']);
    }

    /**
     * Check the string expressions in an expression: the whole of it, laid out, if it's a chain of `.` operations
     * where it can be, or else each chain of `.` operations in it, on one line.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $root The expression.
     * @param int $before The position of the token that ends it before it, or -1.
     * @param int $after The position of the token that ends it after it.
     * @return bool True if the whole expression was checked as a string expression, so its layout is checked.
     */
    private function processStrings(File $phpcsFile, ExpressionNode $root, int $before, int $after): bool
    {
        if (
            self::isConcatenation($phpcsFile, $root)
            && self::isStringLaidOut($phpcsFile, $root->start, $root->end, $before, $after)
        ) {
            $helper = new StringHelper($phpcsFile);
            if ($helper->read($root)) {
                $this->reportString(
                    $phpcsFile,
                    $root->start,
                    $root->end,
                    $this->layoutString($phpcsFile, $helper, $root->start, $root->end),
                );
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether an expression is a whole ternary branch whose `?` or `:` starts a line.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the token that ends the expression before it, or -1.
     * @return bool True if it is.
     */
    private static function isTernaryBranchOnItsOwnLine(File $phpcsFile, int $before): bool
    {
        $tokens = $phpcsFile->getTokens();

        return $before >= 0 && in_array($tokens[$before]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true)
            && LayoutHelper::startsLine($phpcsFile, $before);
    }

    /**
     * Check each chain of `.` operations in a node, rewritten on one line, leaving its line breaks as they are unless
     * it changes.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node.
     */
    private function processNestedStrings(File $phpcsFile, ExpressionNode $node): void
    {
        if ($this->isDelegated($node)) {
            return;
        }

        // Grouping parentheses around a string expression that becomes one string aren't needed.
        $inner = $node->children[0] ?? null;
        if (
            $node->kind === ExpressionNode::GROUP && $node->opener === $node->start && $inner !== null
            && (self::isConcatenation($phpcsFile, $inner) || $this->isStringLiteral($phpcsFile, $inner))
        ) {
            $helper = new StringHelper($phpcsFile);
            if ($helper->read($inner) && count($helper->segments()) === 1) {
                $this->rewriteNestedString($phpcsFile, $node, $helper->flat());
                return;
            }
        }

        if (!self::isConcatenation($phpcsFile, $node)) {
            foreach ($node->children as $child) {
                $this->processNestedStrings($phpcsFile, $child);
            }

            return;
        }

        $helper = new StringHelper($phpcsFile);
        if ($helper->read($node)) {
            $this->rewriteNestedString($phpcsFile, $node, $helper->flat());
        }
    }

    /**
     * Report a string expression inside a larger expression that isn't written as it should be, on one line, unless
     * that wouldn't fit on its line, as the line breaks it would need aren't checked here.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node to replace.
     * @param string $flat The code it should be.
     */
    private function rewriteNestedString(File $phpcsFile, ExpressionNode $node, string $flat): void
    {
        $tokens = $phpcsFile->getTokens();
        $current = '';
        for ($i = $node->start; $i <= $node->end; $i++) {
            $current .= $tokens[$i]['code'] === T_WHITESPACE ? ' ' : $tokens[$i]['content'];
        }

        $column = $tokens[$node->start]['column'] - 1;
        $attached = LayoutHelper::attachedLength($phpcsFile, $node->end);
        if ($current !== $flat && ($column + mb_strlen($flat) + $attached) <= $this->maxLineLength) {
            $this->reportString($phpcsFile, $node->start, $node->end, $flat);
        }
    }

    /**
     * Check a string literal that's a whole expression, which is split if it's too long.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the literal.
     */
    private function processLiteral(File $phpcsFile, int $stackPtr): void
    {
        // A literal in a call on one line is part of the expression around the call, and checked with it.
        $region = ExpressionHelper::findOutermostExpression($phpcsFile, $stackPtr, $this->strict);
        if ($region === null) {
            return;
        }

        // Grouping parentheses around the literal aren't needed.
        [$start, $end, $before, $after] = $region;
        $tokens = $phpcsFile->getTokens();
        $innerStart = $start;
        $innerEnd = $end;
        while (
            $innerStart < $innerEnd && ExpressionHelper::isGrouping($phpcsFile, $innerStart)
            && $tokens[$innerStart]['parenthesis_closer'] === $innerEnd
        ) {
            $innerStart = (int) $phpcsFile->findNext(T_WHITESPACE, ($innerStart + 1), $innerEnd, true);
            $innerEnd = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($innerEnd - 1), $innerStart, true);
        }

        $owner = $before >= 0 ? ($tokens[$before]['parenthesis_owner'] ?? null) : null;
        if (
            $innerStart !== $stackPtr || $innerEnd !== $stackPtr
            || ExpressionHelper::isSkipped($phpcsFile, $start, $end, $before)
            || (is_int($owner) && $tokens[$owner]['code'] === T_DECLARE)
        ) {
            return;
        }

        $node = ExpressionHelper::parse($phpcsFile, $stackPtr, $stackPtr);
        $helper = new StringHelper($phpcsFile);
        if ($node === null || !$helper->read($node)) {
            return;
        }

        if (self::isStringLaidOut($phpcsFile, $start, $end, $before, $after)) {
            $this->reportString($phpcsFile, $start, $end, $this->layoutString($phpcsFile, $helper, $start, $end));
        } elseif ($start !== $stackPtr) {
            $this->rewriteNestedString(
                $phpcsFile,
                new ExpressionNode(ExpressionNode::GROUP, $start, $end, 0),
                $helper->flat(),
            );
        }
    }

    /**
     * Lay out a string expression from where it starts.
     *
     * @param File $phpcsFile The file being scanned.
     * @param StringHelper $helper The expression, read.
     * @param int $start The position of the first token it replaces.
     * @param int $end The position of the last token it replaces.
     * @return string The code.
     */
    private function layoutString(File $phpcsFile, StringHelper $helper, int $start, int $end): string
    {
        $column = $phpcsFile->getTokens()[$start]['column'] - 1;
        $indent = LayoutHelper::lineIndent($phpcsFile, $start) + LayoutHelper::INDENT;
        $attached = LayoutHelper::attachedLength($phpcsFile, $end);
        $code = $helper->layout($column, $indent, $attached, $this->maxLineLength);

        return str_replace("\n", $phpcsFile->eolChar, $code);
    }

    /**
     * Report a string expression that isn't written as it should be, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of its first token.
     * @param int $end The position of its last token.
     * @param string $code The code it should be.
     */
    private function reportString(File $phpcsFile, int $start, int $end, string $code): void
    {
        if ($phpcsFile->getTokensAsString($start, ($end - $start + 1)) === $code) {
            return;
        }

        $error = 'A string expression must be written as the fewest strings it can be, with variables interpolated, '
            . 'and split only where it doesn\'t fit within %s characters.';
        if (
            !$phpcsFile->addFixableWarning($error, $start, self::CODE_INCORRECT_STRING_FORMAT, [$this->maxLineLength])
        ) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($start, $code);
        for ($i = ($start + 1); $i <= $end; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Check each argument of `echo`, `print` or `<?=` for a `(string)` cast or `->__toString()` call, which isn't
     * needed, as they convert their arguments to strings.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the keyword or tag.
     */
    private static function processConversions(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $ptr = $stackPtr;
        do {
            $argument = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($ptr + 1), null, true);
            $region = $argument === false ? null : ExpressionHelper::findExpression($phpcsFile, $argument);
            if ($region === null) {
                return;
            }

            [$start, $end, , $after] = $region;
            $node = ExpressionHelper::parse($phpcsFile, $start, $end);
            if ($node !== null && $node->kind !== ExpressionNode::LEVEL) {
                self::processConversion($phpcsFile, $stackPtr, $start, $end);
            }

            $ptr = $after;
        } while ($tokens[$stackPtr]['code'] !== T_PRINT && ($tokens[$ptr]['code'] ?? null) === T_COMMA);
    }

    /**
     * Report a `(string)` cast or `->__toString()` call around a whole argument of `echo`, `print` or `<?=`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $keyword The position of the keyword or tag.
     * @param int $start The position of the argument's first token.
     * @param int $end The position of its last token.
     */
    private static function processConversion(File $phpcsFile, int $keyword, int $start, int $end): void
    {
        $tokens = $phpcsFile->getTokens();
        $from = null;
        $to = null;
        if ($tokens[$start]['code'] === T_STRING_CAST && $start < $end) {
            $from = $start;
            $to = (int) $phpcsFile->findNext(T_WHITESPACE, ($start + 1), null, true) - 1;
        } elseif (
            ($end - 4) >= $start && $tokens[$end]['code'] === T_CLOSE_PARENTHESIS
            && $tokens[($end - 1)]['code'] === T_OPEN_PARENTHESIS && $tokens[($end - 2)]['code'] === T_STRING
            && strtolower($tokens[($end - 2)]['content']) === '__tostring'
            && $tokens[($end - 3)]['code'] === T_OBJECT_OPERATOR
        ) {
            $from = $end - 3;
            $to = $end;
        }

        if ($from === null) {
            return;
        }

        $keywordName = $tokens[$keyword]['code'] === T_OPEN_TAG_WITH_ECHO
            ? '<?='
            : strtolower($tokens[$keyword]['content']);
        $fix = $phpcsFile->addFixableWarning(
            'The string conversion isn\'t needed, as %s converts its arguments to strings.',
            $from,
            self::CODE_UNNECESSARY_STRING_CONVERSION,
            [$keywordName],
        );
        if ($fix) {
            $phpcsFile->fixer->beginChangeset();
            for ($i = $from; $i <= $to; $i++) {
                $phpcsFile->fixer->replaceToken($i, '');
            }

            $phpcsFile->fixer->endChangeset();
        }
    }

    /**
     * Find the parentheses of the control structure whose condition, or header, an expression is directly in.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @return ?int The position of the opening parenthesis, or null if it isn't in one.
     */
    private static function findConditionOpener(File $phpcsFile, int $start): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $openers = array_keys($tokens[$start]['nested_parenthesis'] ?? []);
        $opener = end($openers);
        $owner = is_int($opener) ? ($tokens[$opener]['parenthesis_owner'] ?? null) : null;

        return is_int($opener) && is_int($owner) && in_array($tokens[$owner]['code'], self::CONDITION_OWNERS, true)
            ? $opener
            : null;
    }

    /**
     * Work out where a node's lines break, splitting it only if it doesn't fit, and as few times as it can.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node.
     * @param int $column The number of characters before the node on its first line.
     * @param int $lineIndent The indent of the node's first line.
     * @param int $indent The indent for a level's new lines.
     * @param int $trailing The number of characters straight after the node on its last line, which can't move to
     * another line without it, such as a `;` or `,`.
     * @param bool $inCondition Whether the node is directly in a control structure's condition, outside any group or
     * operator other than `&&` and `||`.
     * @param array<int, int> $breaks The operators to break the line before, with each one's indent.
     * @return int The number of characters before the node's end, on its last line.
     */
    private function layout(
        File $phpcsFile,
        ExpressionNode $node,
        int $column,
        int $lineIndent,
        int $indent,
        int $trailing,
        bool $inCondition,
        array &$breaks,
    ): int {
        // A body isn't laid out here. Its last line is its `}`, level with the line it starts on.
        if ($node->kind === ExpressionNode::BODY) {
            return $lineIndent + 1;
        }

        $flatEnd = $column + $node->flatLength;
        if ($node->kind === ExpressionNode::OPERAND || ($flatEnd + $trailing) <= $this->maxLineLength) {
            return $flatEnd;
        }

        if ($node->kind === ExpressionNode::CALL) {
            return $this->layoutCall($phpcsFile, $node, $column, $lineIndent, $trailing, $breaks);
        }

        if ($node->kind === ExpressionNode::LIST) {
            return $this->layoutList($phpcsFile, $node, $column, $breaks);
        }

        if ($node->kind === ExpressionNode::TERNARY) {
            return $this->layoutTernary($phpcsFile, $node, $column, $lineIndent, $indent, $trailing, $breaks);
        }

        // A group's contents go on its first line, and any new lines inside it are indented once past the line with
        // its `(`, as PERCS31.Operators.OperatorPlacement requires.
        if ($node->kind === ExpressionNode::GROUP) {
            $inner = $node->children[0];
            $innerColumn = $column + ($node->flatLength - $inner->flatLength - 1);
            $innerIndent = $lineIndent + LayoutHelper::INDENT;

            return $this->layout(
                $phpcsFile,
                $inner,
                $innerColumn,
                $lineIndent,
                $innerIndent,
                ($trailing + 1),
                false,
                $breaks,
            ) + 1;
        }

        if (self::isPair($phpcsFile, $node)) {
            return $this->layoutPair($phpcsFile, $node, $column, $lineIndent, $indent, $trailing, $breaks);
        }

        // In a condition, the parts joined by `&&` and `||` line up, but any other operator's new lines are indented
        // once past the line its operand starts on, as PERCS31.Operators.OperatorPlacement requires.
        $tokens = $phpcsFile->getTokens();
        if ($inCondition && !in_array($tokens[$node->operators[0]]['code'], self::BOOLEAN_OPERATORS, true)) {
            $indent = $lineIndent + LayoutHelper::INDENT;
            $inCondition = false;
        }

        // Each operand joins the line it follows if it fits, and starts a new line, with its operator, if it doesn't.
        $lastIndex = count($node->operators);
        $currentIndent = $lineIndent;
        $first = $node->children[0];
        $end = $this->layout($phpcsFile, $first, $column, $lineIndent, $indent, 0, $inCondition, $breaks);
        $isAfterSplitGroup = $first->kind === ExpressionNode::GROUP && $end !== $column + $first->flatLength;
        foreach ($node->operators as $index => $operator) {
            $child = $node->children[($index + 1)];
            $childTrailing = ($index + 1) === $lastIndex ? $trailing : 0;
            $operatorLength = mb_strlen($tokens[$operator]['content']);
            $joinedEnd = $end + $operatorLength + 2 + $child->flatLength;

            // After a group split over lines, the next operator starts a line.
            if (!$isAfterSplitGroup && ($joinedEnd + $childTrailing) <= $this->maxLineLength) {
                $end = $joinedEnd;
                continue;
            }

            $isAfterSplitGroup = false;

            // Breaking before the last operand saves nothing when it's a call or array too long for a line of its own
            // too: it stays after its operator, and is split there.
            $childColumn = $end + $operatorLength + 2;
            $ownLineEnd = $indent + $operatorLength + 1 + $child->flatLength + $childTrailing;
            if (
                ($index + 1) === $lastIndex
                && in_array($child->kind, [ExpressionNode::CALL, ExpressionNode::BODY], true)
                && $ownLineEnd > $this->maxLineLength
                && ($childColumn + self::firstLineLength($child)) <= $this->maxLineLength
            ) {
                $end = $this->layout(
                    $phpcsFile,
                    $child,
                    $childColumn,
                    $currentIndent,
                    $indent,
                    $childTrailing,
                    false,
                    $breaks,
                );
                continue;
            }

            $breaks[$operator] = $indent;
            $currentIndent = $indent;
            $childColumn = $indent + $operatorLength + 1;

            // A string expression that doesn't fit on its own line is laid out as a string, at the same indent.
            $stringEnd = $this->delegateString($phpcsFile, $child, $childColumn, $indent, $childTrailing, false);
            if ($stringEnd !== null) {
                $end = $stringEnd;
                continue;
            }

            $end = $this->layout(
                $phpcsFile,
                $child,
                $childColumn,
                $indent,
                $indent,
                $childTrailing,
                $inCondition,
                $breaks,
            );
            $isAfterSplitGroup = $child->kind === ExpressionNode::GROUP && $end !== $childColumn + $child->flatLength;
        }

        return $end;
    }

    /**
     * Work out the layout of a ternary that doesn't fit on its line: three lines, the condition, then `?` and its
     * value, then `:` and its value, each new line indented once, as PER Coding Style 3.1, section 6.4, says
     * "ternaries MUST occupy 3 lines, never 2". A short ternary takes two: the condition, then `?:` and its value. Each
     * part is then laid out in the room its line leaves it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The ternary.
     * @param int $column The number of characters before the ternary on its first line.
     * @param int $lineIndent The indent of the ternary's first line.
     * @param int $indent The indent for the ternary's new lines.
     * @param int $trailing The number of characters straight after the ternary on its last line.
     * @param array<int, int> $breaks The operators to break the line before, with each one's indent.
     * @return int The number of characters before the ternary's end, on its last line.
     */
    private function layoutTernary(
        File $phpcsFile,
        ExpressionNode $node,
        int $column,
        int $lineIndent,
        int $indent,
        int $trailing,
        array &$breaks,
    ): int {
        [$question, $colon] = $node->operators;
        $condition = $node->children[0];
        $this->layout($phpcsFile, $condition, $column, $lineIndent, $indent, 0, false, $breaks);
        $values = count($node->children) === 3
            ? [$question => $node->children[1], $colon => $node->children[2]]
            : [$question => $node->children[1]];
        $end = $indent;
        foreach ($values as $operator => $value) {
            $breaks[$operator] = $indent;
            $valueColumn = $indent + (count($values) === 1 ? 3 : 2);
            $valueTrailing = $operator === $question && count($values) === 2 ? 0 : $trailing;
            $continuation = $indent + LayoutHelper::INDENT;
            $end = $this->delegateString($phpcsFile, $value, $valueColumn, $continuation, $valueTrailing, true)
                ?? $this->layout(
                    $phpcsFile,
                    $value,
                    $valueColumn,
                    $indent,
                    $continuation,
                    $valueTrailing,
                    false,
                    $breaks,
                );
        }

        return $end;
    }

    /**
     * Work out the layout of a `match` arm's conditions that are too long for their line: one per line, level with the
     * first, each followed by a comma.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The list of conditions.
     * @param int $column The number of characters before the first condition on its line.
     * @param array<int, int> $breaks The operators to break the line before, and the calls and lists to split.
     * @return int The number of characters before the last condition's comma, on its line, plus the comma.
     */
    private function layoutList(File $phpcsFile, ExpressionNode $node, int $column, array &$breaks): int
    {
        $breaks[$node->separators[0]] = $column;
        $this->listLayouts[$node->separators[0]] = $column;
        $end = $column;
        foreach ($node->children as $child) {
            $end = $this->layout(
                $phpcsFile,
                $child,
                $column,
                $column,
                $column + LayoutHelper::INDENT,
                1,
                false,
                $breaks,
            );
        }

        return $end + 1;
    }

    /**
     * Work out how a call or array that doesn't fit on its line is split. It's chopped down, one argument per line, if
     * that lets each argument fit on its own line. Otherwise, if exactly one argument would span lines however the call
     * is laid out, an array too long even on a line of its own, or a call hugging one, it's hugged: the other arguments
     * stay on the call's lines. Anything else is chopped down. An array's values are always chopped down.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The call or array.
     * @param int $column The number of characters before the node on its first line.
     * @param int $lineIndent The indent of the node's first line.
     * @param int $trailing The number of characters straight after the node on its last line.
     * @param array<int, int> $breaks The operators to break the line before, and the calls to split, with indents.
     * @return int The number of characters before the node's end, on its last line.
     */
    private function layoutCall(
        File $phpcsFile,
        ExpressionNode $node,
        int $column,
        int $lineIndent,
        int $trailing,
        array &$breaks,
    ): int {
        $opener = (int) $node->opener;
        $argumentIndent = $lineIndent + LayoutHelper::INDENT;
        $hugged = $node->isArray ? null : $this->findHuggedArgument($node, $column, $lineIndent, $trailing);
        if ($hugged !== null) {
            // The arguments before the hugged one, and the hugged one's first line, are on the call's first line.
            $breaks[$opener] = $lineIndent;
            $this->callLayouts[$opener] = [null, $hugged];
            $end = $column + self::calleeLength($node) + 1;
            foreach ($node->children as $index => $child) {
                if ($index === $hugged) {
                    $end = $this->layout($phpcsFile, $child, $end, $lineIndent, $argumentIndent, 0, false, $breaks);
                } else {
                    $end += $child->flatLength;
                }

                $end += $index < count($node->children) - 1 ? 2 : 0;
            }

            return $end + 1;
        }

        $breaks[$opener] = $lineIndent;
        $this->callLayouts[$opener] = [$argumentIndent, null];
        // Each argument on its own line, followed by a comma, and its new lines indented once more.
        $continuation = $argumentIndent + LayoutHelper::INDENT;
        foreach ($node->children as $child) {
            if ($this->delegateString($phpcsFile, $child, $argumentIndent, $continuation, 1, true) === null) {
                $this->layout($phpcsFile, $child, $argumentIndent, $argumentIndent, $continuation, 1, false, $breaks);
            }
        }

        return $lineIndent + 1;
    }

    /**
     * Find the one argument of a call to hug, if the call isn't to be chopped down.
     *
     * @param ExpressionNode $node The call.
     * @param int $column The number of characters before the call on its first line.
     * @param int $lineIndent The indent of the call's first line.
     * @param int $trailing The number of characters straight after the call on its last line.
     * @return ?int The argument's index, or null if the call is chopped down.
     */
    private function findHuggedArgument(ExpressionNode $node, int $column, int $lineIndent, int $trailing): ?int
    {
        // Chopped down, each argument on its own line, with a comma after it.
        $argumentIndent = $lineIndent + LayoutHelper::INDENT;
        $spanning = [];
        foreach ($node->children as $index => $child) {
            if ($argumentIndent + $child->flatLength + 1 > $this->maxLineLength) {
                $spanning[] = $index;
            }
        }

        if (count($spanning) !== 1) {
            return null;
        }

        // A closure, an array, or a call hugging an argument of its own. A `match` or anonymous class is never hugged.
        $hugged = $spanning[0];
        $child = $node->children[$hugged];
        $isHuggable = $child->kind === ExpressionNode::BODY
            ? $child->isClosure
            : $child->kind === ExpressionNode::CALL && ($child->isArray || $this->hugsAnArgument($child, $lineIndent));
        if (!$isHuggable) {
            return null;
        }

        // The arguments before it fit on the first line, up to the hugged one's opening bracket or brace, and those
        // after it fit on the last line, after its closing one.
        $before = $column + self::calleeLength($node) + 1 + self::firstLineLength($child);
        $after = $lineIndent + 1;
        foreach ($node->children as $index => $other) {
            if ($index < $hugged) {
                $before += $other->flatLength + 2;
            } elseif ($index > $hugged) {
                $after += 2 + $other->flatLength;
            }
        }

        return $before <= $this->maxLineLength && ($after + 1 + $trailing) <= $this->maxLineLength ? $hugged : null;
    }

    /**
     * Check whether a call, hugged into another, hugs one of its own arguments, so it spans lines however it's laid
     * out.
     *
     * @param ExpressionNode $node The call.
     * @param int $lineIndent The indent of the line it starts on.
     * @return bool True if it does.
     */
    private function hugsAnArgument(ExpressionNode $node, int $lineIndent): bool
    {
        return !$node->isArray && $this->findHuggedArgument($node, 0, $lineIndent, 0) !== null;
    }

    /**
     * Get the length of a call's or body's first line when it's split: up to and including its opening bracket, or its
     * body's `{`.
     *
     * @param ExpressionNode $node The call or body.
     * @return int The length.
     */
    private static function firstLineLength(ExpressionNode $node): int
    {
        return $node->kind === ExpressionNode::BODY ? $node->headLength : self::calleeLength($node) + 1;
    }

    /**
     * Get the length of what comes before a call's opening bracket, such as `foo` or `$this->bar`.
     *
     * @param ExpressionNode $node The call.
     * @return int The length.
     */
    private static function calleeLength(ExpressionNode $node): int
    {
        $arguments = array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $node->children));

        return $node->flatLength - $arguments - 2 * (count($node->children) - 1) - 2;
    }

    /**
     * Work out where a `key => value` pair's lines break, when it doesn't fit on one line. The key and the value each
     * on a line of their own come first:
     * - A key too long for its line is split, at its operators or as a string, and `=>` goes on a line of its own.
     * - A value that fits on a line of its own goes there, starting with the `=>`.
     * - Otherwise there's no break before `=>`, as it would save nothing: the value is split after `key =>`, at its
     *   operators, as a call or array, or as a string. Only when `key =>` leaves no room for even the start of the
     *   value does `=>` start a line anyway, with the value's new lines indented once more.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The pair.
     * @param int $column The number of characters before the pair on its first line.
     * @param int $lineIndent The indent of the pair's first line.
     * @param int $indent The indent for the pair's new lines.
     * @param int $trailing The number of characters straight after the pair on its last line.
     * @param array<int, int> $breaks The operators to break the line before, with each one's indent.
     * @return int The number of characters before the pair's end, on its last line.
     */
    private function layoutPair(
        File $phpcsFile,
        ExpressionNode $node,
        int $column,
        int $lineIndent,
        int $indent,
        int $trailing,
        array &$breaks,
    ): int {
        [$key, $value] = $node->children;
        $arrow = $node->operators[0];
        $keyEnd = $this->delegateString($phpcsFile, $key, $column, $indent, 0, true)
            ?? $this->layout($phpcsFile, $key, $column, $lineIndent, $indent, 0, false, $breaks);
        $isKeySplit = $keyEnd !== $column + $key->flatLength;

        // A string expression that becomes one string is one operand.
        $string = null;
        $valueLength = $value->flatLength;
        if ($this->reflowStringExpressions && self::isConcatenation($phpcsFile, $value)) {
            $helper = new StringHelper($phpcsFile);
            if ($helper->read($value) && count($helper->segments()) === 1) {
                $string = $helper;
                $valueLength = mb_strlen($helper->flat());
            }
        }

        $valueColumn = $keyEnd + 4;
        if (!$isKeySplit && ($valueColumn + $valueLength + $trailing) <= $this->maxLineLength) {
            return $valueColumn + $valueLength;
        }

        // On a line of its own, starting with the `=>`.
        $ownLineColumn = $indent + 3;
        if ($isKeySplit || ($ownLineColumn + $valueLength + $trailing) <= $this->maxLineLength) {
            $breaks[$arrow] = $indent;
            if (($ownLineColumn + $valueLength + $trailing) <= $this->maxLineLength) {
                if ($string !== null) {
                    $this->delegated[$value->start] = [$value, $string->flat()];
                }

                return $ownLineColumn + $valueLength;
            }

            return $this->layoutValue($phpcsFile, $value, $ownLineColumn, $indent, $trailing, $breaks);
        }

        // No room after `key =>` for even the start of the value: `=>` starts a line anyway.
        if (($valueColumn + self::leadLength($value)) > $this->maxLineLength) {
            $breaks[$arrow] = $indent;

            return $this->layoutValue($phpcsFile, $value, $ownLineColumn, $indent, $trailing, $breaks);
        }

        $valueLineIndent = $this->isDelegated($key) ? $indent : $lineIndent;

        return $this->delegateString($phpcsFile, $value, $valueColumn, $indent, $trailing, true)
            ?? $this->layout($phpcsFile, $value, $valueColumn, $valueLineIndent, $indent, $trailing, false, $breaks);
    }

    /**
     * Lay out a pair's value that starts a line of its own, after `=>`, and doesn't fit on it. Its new lines are
     * indented once past that line.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $value The value.
     * @param int $column The number of characters before the value on its line.
     * @param int $lineIndent The indent of that line.
     * @param int $trailing The number of characters straight after the value on its last line.
     * @param array<int, int> $breaks The operators to break the line before, with each one's indent.
     * @return int The number of characters before the value's end, on its last line.
     */
    private function layoutValue(
        File $phpcsFile,
        ExpressionNode $value,
        int $column,
        int $lineIndent,
        int $trailing,
        array &$breaks,
    ): int {
        $indent = $lineIndent + LayoutHelper::INDENT;

        return $this->delegateString($phpcsFile, $value, $column, $indent, $trailing, true)
            ?? $this->layout($phpcsFile, $value, $column, $lineIndent, $indent, $trailing, false, $breaks);
    }

    /**
     * Get the length of a node's first piece, which can't be split from the start of its line: a level's first operand,
     * a ternary's condition's, a call's name and opening bracket, or a body's first line.
     *
     * @param ExpressionNode $node The node.
     * @return int The length.
     */
    private static function leadLength(ExpressionNode $node): int
    {
        return match ($node->kind) {
            ExpressionNode::LEVEL, ExpressionNode::TERNARY => self::leadLength($node->children[0]),
            ExpressionNode::GROUP
                => $node->flatLength - $node->children[0]->flatLength - 1 + self::leadLength($node->children[0]),
            ExpressionNode::CALL, ExpressionNode::BODY => self::firstLineLength($node),
            default => $node->flatLength,
        };
    }

    /**
     * Lay out a string expression that doesn't fit on its line as a string, whose line breaks aren't checked here.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node.
     * @param int $column The number of characters before the node on its first line.
     * @param int $indent The indent for its new lines.
     * @param int $trailing The number of characters straight after it on its last line.
     * @param bool $includeLiteral Whether a single string literal is laid out too, not only a chain of `.` operations.
     * @return ?int The number of characters before its end, on its last line, or null if it isn't laid out.
     */
    private function delegateString(
        File $phpcsFile,
        ExpressionNode $node,
        int $column,
        int $indent,
        int $trailing,
        bool $includeLiteral,
    ): ?int {
        $isString = self::isConcatenation($phpcsFile, $node)
            || ($includeLiteral && $node->kind === ExpressionNode::OPERAND
                && $this->isStringLiteral($phpcsFile, $node));
        if (
            !$this->reflowStringExpressions || !$isString
            || ($column + $node->flatLength + $trailing) <= $this->maxLineLength
        ) {
            return null;
        }

        $helper = new StringHelper($phpcsFile);
        if (!$helper->read($node)) {
            return null;
        }

        $code = $helper->layout($column, $indent, $trailing, $this->maxLineLength);
        $this->delegated[$node->start] = [$node, str_replace("\n", $phpcsFile->eolChar, $code)];
        $lastNewline = strrpos($code, "\n");

        return $lastNewline === false ? $column + mb_strlen($code) : mb_strlen(substr($code, ($lastNewline + 1)));
    }

    /**
     * Find the operators a node's lines break at now, before or after them, and whether a group's parentheses have a
     * line break inside them.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The node.
     * @param array<int, true> $current The operators with a line break before or after them.
     * @param bool $misplaced Set if a group's parentheses don't hug its contents.
     */
    private function findCurrentBreaks(File $phpcsFile, ExpressionNode $node, array &$current, bool &$misplaced): void
    {
        if ($this->isDelegated($node)) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        foreach ($node->operators as $operator) {
            $previous = $phpcsFile->findPrevious(T_WHITESPACE, ($operator - 1), null, true);
            $next = $phpcsFile->findNext(T_WHITESPACE, ($operator + 1), null, true);
            if ($previous !== false && $tokens[$previous]['line'] !== $tokens[$operator]['line']) {
                $current[$operator] = true;
            }

            if ($next !== false && $tokens[$next]['line'] !== $tokens[$operator]['line']) {
                $current[$operator] = true;
            }
        }

        if ($node->kind === ExpressionNode::GROUP && is_int($node->opener) && is_int($node->closer)) {
            $inner = $node->children[0];
            $misplaced = $misplaced || $tokens[$node->opener]['line'] !== $tokens[$inner->start]['line']
                || $tokens[$node->closer]['line'] !== $tokens[$inner->end]['line'];
        }

        // A `match` arm's conditions: one per line, or all on one.
        if ($node->kind === ExpressionNode::LIST) {
            $broken = [];
            foreach ($node->separators as $index => $comma) {
                $broken[] = $tokens[$comma]['line'] !== $tokens[$node->children[$index + 1]->start]['line'];
            }

            if (in_array(true, $broken, true)) {
                $current[$node->separators[0]] = true;
                $misplaced = $misplaced || in_array(false, $broken, true);
            }
        }

        // A call split over lines: as it should be, if it's chopped down or hugging the same argument as worked out.
        if ($node->kind === ExpressionNode::CALL && is_int($node->opener)) {
            $layout = self::findCallLayout($phpcsFile, $node);
            if ($layout !== self::FLAT) {
                $current[$node->opener] = true;
                $expected = $this->callLayouts[$node->opener] ?? null;
                $isExpected = $expected !== null
                    && (($layout === self::CHOPPED && $expected[0] !== null) || $layout === $expected[1]);
                $misplaced = $misplaced || ($expected !== null && !$isExpected) || $layout === self::OTHER;
            }
        }

        foreach ($node->children as $child) {
            $this->findCurrentBreaks($phpcsFile, $child, $current, $misplaced);
        }
    }

    /**
     * Find how a call or array is laid out now.
     *
     * @param File $phpcsFile The file being scanned.
     * @param ExpressionNode $node The call.
     * @return int|string FLAT if it's on one line, CHOPPED if each argument starts a line and the closer has one of its
     * own, the index of the one argument that spans lines with the rest on the call's lines, or OTHER.
     */
    private static function findCallLayout(File $phpcsFile, ExpressionNode $node): int|string
    {
        $tokens = $phpcsFile->getTokens();
        $last = $node->children[count($node->children) - 1];
        $gaps = [[(int) $node->opener, $node->children[0]->start]];
        foreach ($node->separators as $index => $comma) {
            $gaps[] = [$comma, $node->children[$index + 1]->start];
        }

        $gaps[] = [$node->trailingComma ?? $last->end, (int) $node->closer];
        $isBroken = static fn(array $gap): bool => $tokens[$gap[0]]['line'] !== $tokens[$gap[1]]['line'];
        $broken = array_map($isBroken, $gaps);
        if (!in_array(false, $broken, true)) {
            return self::CHOPPED;
        }

        if (in_array(true, $broken, true)) {
            return self::OTHER;
        }

        $spanning = array_keys(array_filter(
            $node->children,
            static fn(ExpressionNode $child): bool => $tokens[$child->start]['line'] !== $tokens[$child->end]['line'],
        ));

        return match (count($spanning)) {
            0 => self::FLAT,
            1 => $spanning[0],
            default => self::OTHER,
        };
    }

    /**
     * Set the whitespace around each operator and inside each group's parentheses to match the layout.
     *
     * @param File $phpcsFile The file being fixed.
     * @param ExpressionNode $node The node.
     * @param array<int, int> $breaks The operators to break the line before, with each one's indent.
     */
    private function applyLayout(File $phpcsFile, ExpressionNode $node, array $breaks): void
    {
        if ($this->isDelegated($node)) {
            return;
        }

        // A `match` arm's conditions first, so a comma added after the last comes before the line break after it.
        foreach ($node->children as $child) {
            if ($child->kind === ExpressionNode::LIST) {
                $this->applyListLayout($phpcsFile, $child);
            }
        }

        // A short ternary's `?` and `:` are written together, as `?:`, after the condition.
        if ($node->kind === ExpressionNode::TERNARY && count($node->children) === 2) {
            [$question, $colon] = $node->operators;
            $before = isset($breaks[$question]) ? $phpcsFile->eolChar . str_repeat(' ', $breaks[$question]) : ' ';
            self::setGap($phpcsFile, $node->children[0]->end, $question, $before);
            self::setGap($phpcsFile, $question, $colon, '');
            self::setGap($phpcsFile, $colon, $node->children[1]->start, ' ');
        } else {
            foreach ($node->operators as $index => $operator) {
                $before = isset($breaks[$operator]) ? $phpcsFile->eolChar . str_repeat(' ', $breaks[$operator]) : ' ';
                self::setGap($phpcsFile, $node->children[$index]->end, $operator, $before);
                self::setGap($phpcsFile, $operator, $node->children[($index + 1)]->start, ' ');
            }
        }

        if ($node->kind === ExpressionNode::GROUP && is_int($node->opener) && is_int($node->closer)) {
            self::setGap($phpcsFile, $node->opener, $node->children[0]->start, '');
            self::setGap($phpcsFile, $node->children[0]->end, $node->closer, '');
        }

        $argumentIndent = $this->callLayouts[$node->opener ?? -1][0] ?? null;
        if ($node->kind === ExpressionNode::CALL && is_int($argumentIndent) && is_int($node->closer)) {
            // Chopped down: each argument on its own line, with a comma after the last, and the closer on its own line.
            $newLine = $phpcsFile->eolChar . str_repeat(' ', $argumentIndent);
            self::setGap($phpcsFile, (int) $node->opener, $node->children[0]->start, $newLine);
            foreach ($node->separators as $index => $comma) {
                self::setGap($phpcsFile, $comma, $node->children[$index + 1]->start, $newLine);
            }

            $last = $node->children[count($node->children) - 1]->end;
            if ($node->trailingComma === null) {
                $phpcsFile->fixer->addContent($last, ',');
            }

            $closerLine = $phpcsFile->eolChar . str_repeat(' ', $argumentIndent - LayoutHelper::INDENT);
            self::setGap($phpcsFile, $node->trailingComma ?? $last, $node->closer, $closerLine);
        } elseif ($node->kind === ExpressionNode::CALL && self::findCallLayout($phpcsFile, $node) !== self::FLAT) {
            // Hugging an argument, or on one line: the list itself isn't split, so it has no trailing comma.
            self::setGap($phpcsFile, (int) $node->opener, $node->children[0]->start, '');
            foreach ($node->separators as $index => $comma) {
                self::setGap($phpcsFile, $comma, $node->children[$index + 1]->start, ' ');
            }

            self::setGap($phpcsFile, $node->children[count($node->children) - 1]->end, (int) $node->closer, '');
        }

        foreach ($node->children as $child) {
            $this->applyLayout($phpcsFile, $child, $breaks);
        }
    }

    /**
     * Set the whitespace between a `match` arm's conditions: one per line, level with the first, with a comma after the
     * last, or all on one line, without one.
     *
     * @param File $phpcsFile The file being fixed.
     * @param ExpressionNode $node The list of conditions.
     */
    private function applyListLayout(File $phpcsFile, ExpressionNode $node): void
    {
        $indent = $this->listLayouts[$node->separators[0]] ?? null;
        $last = $node->children[count($node->children) - 1]->end;
        $gap = $indent === null ? ' ' : $phpcsFile->eolChar . str_repeat(' ', $indent);
        foreach ($node->separators as $index => $comma) {
            self::setGap($phpcsFile, $comma, $node->children[$index + 1]->start, $gap);
        }

        if ($indent !== null && $node->trailingComma === null) {
            $phpcsFile->fixer->addContent($last, ',');
        } elseif ($indent === null && $node->trailingComma !== null) {
            self::setGap($phpcsFile, $last, $node->trailingComma, '');
            $phpcsFile->fixer->replaceToken($node->trailingComma, '');
        }
    }

    /**
     * Check whether a node is a string expression laid out as a string, whose line breaks aren't checked here.
     *
     * @param ExpressionNode $node The node.
     * @return bool True if it is.
     */
    private function isDelegated(ExpressionNode $node): bool
    {
        return isset($this->delegated[$node->start]) && $this->delegated[$node->start][0] === $node;
    }

    /**
     * Replace the whitespace between two tokens, if it isn't what's wanted already.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $from The token before the gap.
     * @param int $to The token after the gap.
     * @param string $content The whitespace wanted.
     */
    private static function setGap(File $phpcsFile, int $from, int $to, string $content): void
    {
        if ($phpcsFile->getTokensAsString(($from + 1), ($to - $from - 1)) !== $content) {
            FixerHelper::setBetween($phpcsFile, $from, $to, $content);
        }
    }
}
