<?php

declare(strict_types=1);

namespace PERCS31\Helpers;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Helper class for reading an expression's binary operators and grouping parentheses into a tree, shared by
 * PERCS31.Formatting.ExpressionFormat, which lays expressions out, and Jumbo.Formatting.RequireSingleLineStatement,
 * which leaves them to it.
 */
class ExpressionHelper
{
    /**
     * Binary operators by precedence, from the loosest to the tightest, as in the PHP manual. The pipe operator `|>` is
     * added when PHP defines it.
     *
     * Assignment operators, and `and`, `or` and `xor`, which bind more loosely than assignment, aren't included. The
     * `=>` of an array element, or of a `match` arm with one condition, is put first, so a `key => value` pair is read
     * as one expression, with the key and value as its operands (see isArrowOperator()). It isn't laid out as the
     * loosest operator, though: PERCS31.Formatting.ExpressionFormat splits a value at its own operators first.
     *
     * @var list<list<int|string>>
     */
    private const array PRECEDENCE_LEVELS = [
        [T_DOUBLE_ARROW, T_MATCH_ARROW],
        [T_COALESCE],
        [T_BOOLEAN_OR],
        [T_BOOLEAN_AND],
        [T_BITWISE_OR],
        [T_BITWISE_XOR],
        [T_BITWISE_AND],
        [T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_SPACESHIP],
        [T_LESS_THAN, T_GREATER_THAN, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL],
        [],
        [T_STRING_CONCAT],
        [T_SL, T_SR],
        [T_PLUS, T_MINUS],
        [T_MULTIPLY, T_DIVIDE, T_MODULUS],
        [T_INSTANCEOF],
        [T_POW],
    ];

    /**
     * The index of the pipe operator's level in PRECEDENCE_LEVELS.
     */
    private const int PIPE_LEVEL = 9;

    /**
     * Tokens that end an expression, besides assignment operators and unmatched brackets.
     *
     * @var list<int|string>
     */
    private const array BOUNDARIES = [
        T_SEMICOLON,
        T_COMMA,
        T_DOUBLE_ARROW,
        T_MATCH_ARROW,
        T_FN_ARROW,
        T_INLINE_THEN,
        T_INLINE_ELSE,
        T_COLON,
        T_RETURN,
        T_ECHO,
        T_PRINT,
        T_YIELD,
        T_YIELD_FROM,
        T_THROW,
        T_CASE,
        T_AS,
        T_ELLIPSIS,
        T_LOGICAL_AND,
        T_LOGICAL_OR,
        T_LOGICAL_XOR,
        T_INCLUDE,
        T_INCLUDE_ONCE,
        T_REQUIRE,
        T_REQUIRE_ONCE,
        T_OPEN_TAG,
        T_OPEN_TAG_WITH_ECHO,
        T_CLOSE_TAG,
        T_INLINE_HTML,
        T_OPEN_PARENTHESIS,
        T_OPEN_SQUARE_BRACKET,
        T_OPEN_SHORT_ARRAY,
        T_OPEN_CURLY_BRACKET,
        T_OPEN_USE_GROUP,
        T_ATTRIBUTE,
    ];

    /**
     * Tokens that show a `=>` isn't in an array, when walking back from it to the bracket it's in: an unmatched opener
     * of another kind, or the start of a `foreach` value, `yield` or statement.
     *
     * @var list<int|string>
     */
    private const array NOT_IN_ARRAY = [
        T_OPEN_SQUARE_BRACKET,
        T_OPEN_CURLY_BRACKET,
        T_OPEN_USE_GROUP,
        T_ATTRIBUTE,
        T_SEMICOLON,
        T_AS,
        T_YIELD,
        T_YIELD_FROM,
        T_OPEN_TAG,
        T_OPEN_TAG_WITH_ECHO,
    ];

    /**
     * Prefix operators that can come before an operand's grouping parentheses, besides casts.
     *
     * @var list<int|string>
     */
    private const array PREFIXES = [T_BOOLEAN_NOT, T_BITWISE_NOT, T_ASPERAND, T_MINUS, T_PLUS];

    /**
     * Each binary operator's precedence, once worked out.
     *
     * @var array<int|string, int>|null
     */
    private static ?array $precedences = null;

    /**
     * Get each binary operator's precedence.
     *
     * @return array<int|string, int> Token code => precedence, from 0 for the loosest.
     */
    public static function precedences(): array
    {
        if (self::$precedences === null) {
            self::$precedences = [];
            foreach (self::PRECEDENCE_LEVELS as $precedence => $codes) {
                $codes = $precedence === self::PIPE_LEVEL ? OperatorHelper::pipeOperator() : $codes;
                foreach ($codes as $code) {
                    self::$precedences[$code] = $precedence;
                }
            }
        }

        return self::$precedences;
    }

    /**
     * Check whether a token is one of the binary operators, not a unary `+` or `-`, or a reference.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @return bool True if it is.
     */
    public static function isBinary(File $phpcsFile, int $stackPtr): bool
    {
        $code = $phpcsFile->getTokens()[$stackPtr]['code'];
        if (!isset(self::precedences()[$code])) {
            return false;
        }

        if ($code === T_DOUBLE_ARROW || $code === T_MATCH_ARROW) {
            return self::isArrowOperator($phpcsFile, $stackPtr);
        }

        return !in_array($code, [T_PLUS, T_MINUS, T_BITWISE_AND], true)
            || !OperatorHelper::isUnary($phpcsFile, $stackPtr);
    }

    /**
     * Check whether a `=>` joins a `key => value` pair that's laid out as one expression: an array element's, or a
     * `match` arm's with one condition, or `default`.
     *
     * A `foreach` or `yield` `=>` isn't one, and neither is the `=>` of a `match` arm with several conditions, whose
     * place Jumbo.ControlStructures.MatchArmWrapIndent sets, so each of those ends an expression.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `=>`.
     * @return bool True if it is.
     */
    public static function isArrowOperator(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();
        if ($tokens[$stackPtr]['code'] === T_MATCH_ARROW) {
            return self::isSingleConditionArm($phpcsFile, $stackPtr);
        }

        // Walk back over the array's elements to its opening bracket, skipping anything in brackets.
        for ($i = ($stackPtr - 1); $i >= 0; $i--) {
            $opener = TokenHelper::findOpener($tokens[$i]);
            if ($opener !== null) {
                $i = $opener;
                continue;
            }

            $code = $tokens[$i]['code'];
            if ($code === T_OPEN_SHORT_ARRAY) {
                return true;
            }

            if ($code === T_OPEN_PARENTHESIS) {
                $owner = $tokens[$i]['parenthesis_owner'] ?? null;

                return is_int($owner) && in_array($tokens[$owner]['code'], [T_ARRAY, T_LIST], true);
            }

            if (in_array($code, self::NOT_IN_ARRAY, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Check whether a `match` arm's `=>` follows a single condition, or `default`, with no trailing comma.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $arrow The position of the `=>`.
     * @return bool True if it does.
     */
    private static function isSingleConditionArm(File $phpcsFile, int $arrow): bool
    {
        $tokens = $phpcsFile->getTokens();
        $matches = array_keys($tokens[$arrow]['conditions'], T_MATCH, true);
        $match = end($matches);
        $opener = is_int($match) ? ($tokens[$match]['scope_opener'] ?? null) : null;
        $closer = is_int($match) ? ($tokens[$match]['scope_closer'] ?? null) : null;
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($arrow - 1), null, true);
        if (!is_int($opener) || !is_int($closer) || $previous === false || $tokens[$previous]['code'] === T_COMMA) {
            return false;
        }

        foreach (MatchHelper::findArms($phpcsFile, $opener, $closer) as $arm) {
            if ($arm['arrow'] === $arrow) {
                return count(MatchHelper::findConditions($phpcsFile, $arm)) === 1;
            }
        }

        return false;
    }

    /**
     * Check whether parentheses group part of an expression, rather than belong to a call, a declaration or a control
     * structure.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the opening parenthesis.
     * @return bool True if they're grouping parentheses.
     */
    public static function isGrouping(File $phpcsFile, int $opener): bool
    {
        $tokens = $phpcsFile->getTokens();

        return $tokens[$opener]['code'] === T_OPEN_PARENTHESIS && !isset($tokens[$opener]['parenthesis_owner'])
            && !TokenHelper::isArgumentListOpener($phpcsFile, $opener);
    }

    /**
     * Read the expression an operator is part of, unless it's left alone.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the operator.
     * @param array{int, int, int, int}|null $region The expression's region, if it's been found already.
     * @return array{ExpressionNode, int, int}|null The expression's tree, and the positions of the tokens that end it
     * before and after, or null if it's left alone or has a line break inside an operand. The one before is -1 at the
     * start of the file.
     */
    public static function read(File $phpcsFile, int $stackPtr, ?array $region = null): ?array
    {
        $region ??= self::findExpression($phpcsFile, $stackPtr);
        if ($region === null || self::isSkipped($phpcsFile, $region[0], $region[1], $region[2])) {
            return null;
        }

        $root = self::parse($phpcsFile, $region[0], $region[1]);

        return $root === null || $root->kind === ExpressionNode::OPERAND ? null : [$root, $region[2], $region[3]];
    }

    /**
     * Find the expression an operator is part of, through any grouping parentheses around it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the operator.
     * @param ?int $skipTo The position of a closing bracket to look for the expression's end after, when $stackPtr is
     * its opening bracket.
     * @return array{int, int, int, int}|null The positions of the first and last tokens, and of the tokens that end the
     * expression before and after it, or null if there's an arrow function in the way. The one before is -1 at the
     * start of the file.
     */
    public static function findExpression(File $phpcsFile, int $stackPtr, ?int $skipTo = null): ?array
    {
        $tokens = $phpcsFile->getTokens();
        for ($before = ($stackPtr - 1); $before >= 0; $before--) {
            $code = $tokens[$before]['code'];
            if ($code === T_CLOSE_CURLY_BRACKET && OperatorHelper::closesStatementBlock($phpcsFile, $before)) {
                break;
            }

            // Skip a bracketed group, or a closure, `match` or anonymous class body, back to its keyword.
            $opener = TokenHelper::findOpener($tokens[$before]);
            if ($opener !== null) {
                $owner = $tokens[$opener]['scope_condition'] ?? null;
                $before = is_int($owner) && $owner < $opener ? $owner : $opener;
                continue;
            }

            if (self::isGrouping($phpcsFile, $before)) {
                continue;
            }

            if (self::isBoundary($phpcsFile, $before)) {
                break;
            }
        }

        $count = count($tokens);
        for ($after = (max($stackPtr, $skipTo ?? $stackPtr) + 1); $after < $count; $after++) {
            $code = $tokens[$after]['code'];
            if ($code === T_FN) {
                return null;
            }

            // Skip a closure, `match` or anonymous class to the end of its body.
            $bodyCloser = $tokens[$after]['scope_closer'] ?? null;
            if (in_array($code, [T_CLOSURE, T_MATCH, T_ANON_CLASS], true) && is_int($bodyCloser)) {
                $after = $bodyCloser;
                continue;
            }

            $opener = TokenHelper::findOpener($tokens[$after]);
            if ($opener !== null) {
                if (self::isGrouping($phpcsFile, $opener)) {
                    continue;
                }

                break;
            }

            $closer = TokenHelper::findCloser($tokens[$after]);
            if ($closer !== null && $closer > $after) {
                $after = $closer;
                continue;
            }

            if (self::isBoundary($phpcsFile, $after)) {
                break;
            }
        }

        $start = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($before + 1), ($stackPtr + 1), true);
        $end = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($after - 1), ($stackPtr - 1), true);
        if ($start === false || $end === false) {
            return null;
        }

        return [$start, $end, $before, $after];
    }

    /**
     * Find the outermost expression a token is part of, through any grouping parentheses, and any call or array
     * written on one line, around it. So a call is laid out with the expression it's in, outermost first. An argument
     * of a call split over lines is an expression of its own.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @return array{int, int, int, int}|null As findExpression() returns it.
     */
    public static function findOutermostExpression(File $phpcsFile, int $stackPtr): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $ptr = $stackPtr;
        while (true) {
            $container = TokenHelper::findContainer($phpcsFile, $ptr);
            $closer = $container === null ? null : TokenHelper::findCloser($tokens[$container]);
            if ($container === null || $closer === null) {
                break;
            }

            $isOneLineCall = self::isCallOpener($phpcsFile, $container)
                && $tokens[$container]['line'] === $tokens[$closer]['line'];
            if (!$isOneLineCall && !self::isGrouping($phpcsFile, $container)) {
                break;
            }

            $ptr = $container;
        }

        // From an opening bracket, the expression's end is looked for after its closer.
        $isOpener = self::isCallOpener($phpcsFile, $ptr) || self::isGrouping($phpcsFile, $ptr);

        return self::findExpression($phpcsFile, $ptr, $isOpener ? TokenHelper::findCloser($tokens[$ptr]) : null);
    }

    /**
     * Check whether a token ends an expression: one of the boundaries, an assignment operator, or a `=>` that isn't a
     * `key => value` pair's operator.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the token.
     * @return bool True if it does.
     */
    public static function isBoundary(File $phpcsFile, int $ptr): bool
    {
        $code = $phpcsFile->getTokens()[$ptr]['code'];
        if (in_array($code, [T_DOUBLE_ARROW, T_MATCH_ARROW], true)) {
            return !self::isArrowOperator($phpcsFile, $ptr);
        }

        return in_array($code, self::BOUNDARIES, true) || isset(Tokens::ASSIGNMENT_TOKENS[$code]);
    }

    /**
     * Check whether an expression is left alone: a `case` condition, a `catch` type list, or one with a comment, a
     * heredoc, a nowdoc, a multi-line string or an arrow function.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @param int $before The position of the token before it, or -1.
     * @return bool True if it's skipped.
     */
    public static function isSkipped(File $phpcsFile, int $start, int $end, int $before): bool
    {
        $tokens = $phpcsFile->getTokens();
        if ($before >= 0) {
            $owner = $tokens[$before]['parenthesis_owner'] ?? null;
            if ($tokens[$before]['code'] === T_CASE || (is_int($owner) && $tokens[$owner]['code'] === T_CATCH)) {
                return true;
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $code = $tokens[$i]['code'];
            if (
                isset(Tokens::COMMENT_TOKENS[$code]) || in_array($code, [T_START_HEREDOC, T_START_NOWDOC, T_FN], true)
                || ($code !== T_WHITESPACE && str_contains($tokens[$i]['content'], "\n"))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse part of an expression into a tree of its operands, groups and levels of operators.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @return ?ExpressionNode The node, or null if an operand has a line break inside it.
     */
    public static function parse(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $operators = [];
        for ($i = $start; $i <= $end; $i++) {
            if ($i !== $start && self::isBinary($phpcsFile, $i)) {
                $operators[] = $i;
            }

            $closer = TokenHelper::findCloser($tokens[$i]);
            if ($closer !== null && $closer > $i) {
                $i = $closer;
            }
        }

        return $operators === []
            ? self::parseOperand($phpcsFile, $start, $end)
            : self::parseLevel($phpcsFile, $start, $end, $operators);
    }

    /**
     * Parse a run of operands and operators into a level of its loosest operators, whose operands are levels of the
     * tighter ones.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @param non-empty-list<int> $operators The positions of the operators, outside any brackets.
     * @return ?ExpressionNode The level, or null if an operand has a line break inside it.
     */
    private static function parseLevel(File $phpcsFile, int $start, int $end, array $operators): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $precedences = self::precedences();
        $loosest = min(
            array_map(static fn(int $operator): int => $precedences[$tokens[$operator]['code']], $operators),
        );

        $children = [];
        $levelOperators = [];
        $flatLength = 0;
        $segmentStart = $start;
        $segmentOperators = [];
        foreach ([...$operators, null] as $operator) {
            if ($operator !== null && $precedences[$tokens[$operator]['code']] !== $loosest) {
                $segmentOperators[] = $operator;
                continue;
            }

            $segmentEnd = $end;
            if ($operator !== null) {
                $segmentEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($operator - 1), null, true);
            }

            if ($segmentEnd === false) {
                return null;
            }

            $child = $segmentOperators === []
                ? self::parseOperand($phpcsFile, $segmentStart, $segmentEnd)
                : self::parseLevel($phpcsFile, $segmentStart, $segmentEnd, $segmentOperators);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
            $flatLength += $child->flatLength;
            if ($operator !== null) {
                $next = $phpcsFile->findNext(T_WHITESPACE, ($operator + 1), null, true);
                if ($next === false) {
                    return null;
                }

                $levelOperators[] = $operator;
                $flatLength += mb_strlen($tokens[$operator]['content']) + 2;
                $segmentStart = $next;
                $segmentOperators = [];
            }
        }

        return new ExpressionNode(ExpressionNode::LEVEL, $start, $end, $flatLength, $children, $levelOperators);
    }

    /**
     * Parse an operand: grouping parentheses, with any prefix operators before them, or anything else, which is kept as
     * it's written.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @return ?ExpressionNode The operand or group, or null if it has a line break inside it.
     */
    private static function parseOperand(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $opener = $start;
        while (
            $opener < $end
            && ($tokens[$opener]['code'] === T_WHITESPACE || in_array($tokens[$opener]['code'], self::PREFIXES, true)
                || isset(Tokens::CAST_TOKENS[$tokens[$opener]['code']]))
        ) {
            $opener++;
        }

        $prefix = $phpcsFile->getTokensAsString($start, ($opener - $start));
        $isGroup = self::isGrouping($phpcsFile, $opener) && ($tokens[$opener]['parenthesis_closer'] ?? null) === $end;
        if (!$isGroup) {
            $text = $phpcsFile->getTokensAsString($start, ($end - $start + 1));

            if (str_contains($text, "\n")) {
                return null;
            }

            return self::parseCall($phpcsFile, $start, $end)
                ?? new ExpressionNode(ExpressionNode::OPERAND, $start, $end, mb_strlen($text));
        }

        $innerStart = $phpcsFile->findNext(T_WHITESPACE, ($opener + 1), $end, true);
        $innerEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($end - 1), $opener, true);
        if (str_contains($prefix, "\n") || $innerStart === false || $innerEnd === false) {
            return null;
        }

        $inner = self::parse($phpcsFile, $innerStart, $innerEnd);
        if ($inner === null) {
            return null;
        }

        $flatLength = mb_strlen($prefix) + $inner->flatLength + 2;

        return new ExpressionNode(ExpressionNode::GROUP, $start, $end, $flatLength, [$inner], [], $opener, $end);
    }

    /**
     * Check whether a bracket opens a call's argument list or an array's values, which ExpressionFormat lays out.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the opening parenthesis or bracket.
     * @return bool True if it does.
     */
    public static function isCallOpener(File $phpcsFile, int $opener): bool
    {
        $tokens = $phpcsFile->getTokens();
        if (isset($tokens[$opener]['nested_attributes'])) {
            return false;
        }

        if ($tokens[$opener]['code'] === T_OPEN_SHORT_ARRAY) {
            return true;
        }

        $owner = $tokens[$opener]['parenthesis_owner'] ?? null;
        if (is_int($owner) && $tokens[$owner]['code'] === T_ARRAY) {
            return true;
        }

        // A list() is assigned to, not laid out.
        $before = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($opener - 1), null, true);

        return TokenHelper::isArgumentListOpener($phpcsFile, $opener)
            && ($before === false || $tokens[$before]['code'] !== T_LIST);
    }

    /**
     * Parse an operand that ends with a call's argument list or an array's values, with at least one, into a call.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the operand's first token.
     * @param int $end The position of its last token.
     * @return ?ExpressionNode The call, or null if the operand isn't one, or an argument can't be read.
     */
    private static function parseCall(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $opener = TokenHelper::findOpener($tokens[$end]);
        if ($opener === null || $opener < $start || !self::isCallOpener($phpcsFile, $opener)) {
            return null;
        }

        // A short array that's assigned to is a destructuring list.
        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($end + 1), null, true);
        if ($tokens[$opener]['code'] === T_OPEN_SHORT_ARRAY && $next !== false && $tokens[$next]['code'] === T_EQUAL) {
            return null;
        }

        // The commas between the arguments, outside any brackets or bodies in them.
        $commas = [];
        for ($i = ($opener + 1); $i < $end; $i++) {
            if ($tokens[$i]['code'] === T_COMMA) {
                $commas[] = $i;
            }

            $closer = TokenHelper::findCloser($tokens[$i]) ?? $tokens[$i]['scope_closer'] ?? null;
            if (is_int($closer) && $closer > $i) {
                $i = $closer;
            }
        }

        $children = [];
        $separators = [];
        $trailingComma = null;
        $argumentStart = $opener;
        foreach ([...$commas, $end] as $boundary) {
            $first = $phpcsFile->findNext(T_WHITESPACE, ($argumentStart + 1), $boundary, true);
            if ($first === false) {
                // Nothing after the last comma: a trailing comma.
                if ($boundary === $end && $commas !== []) {
                    $trailingComma = array_pop($separators);
                }

                break;
            }

            $last = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($boundary - 1), $first, true);
            $child = self::parse($phpcsFile, $first, $last);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
            if ($boundary !== $end) {
                $separators[] = $boundary;
            }

            $argumentStart = $boundary;
        }

        if ($children === []) {
            return null;
        }

        // On one line: what comes before the bracket, the bracket, the arguments with ", " between, and the closer.
        $flatLength = mb_strlen($phpcsFile->getTokensAsString($start, ($opener - $start))) + 2
            + array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + 2 * (count($children) - 1);
        // isCallOpener() only takes an owned parenthesis if it's array()'s.
        $isArray = $tokens[$opener]['code'] === T_OPEN_SHORT_ARRAY || isset($tokens[$opener]['parenthesis_owner']);

        return new ExpressionNode(
            ExpressionNode::CALL,
            $start,
            $end,
            $flatLength,
            $children,
            [],
            $opener,
            $end,
            $separators,
            $trailingComma,
            $isArray,
        );
    }

    /**
     * Check whether a line break between two tokens is part of an expression's layout, which
     * PERCS31.Formatting.ExpressionFormat checks: next to a binary operator, or just inside grouping parentheses next
     * to or holding one.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the code before the line break.
     * @param int $after The position of the code after it.
     * @return bool True if it is.
     */
    public static function isExpressionBreak(File $phpcsFile, int $before, int $after): bool
    {
        $operator = self::findOperatorBeside($phpcsFile, $before, $after);

        return $operator !== null && self::read($phpcsFile, $operator) !== null;
    }

    /**
     * Find a binary operator a line break belongs to: one on either side of it, or, for a break just inside grouping
     * parentheses, the first one inside them or one beside them.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the code before the line break.
     * @param int $after The position of the code after it.
     * @return ?int The operator's position, or null if there's none.
     */
    private static function findOperatorBeside(File $phpcsFile, int $before, int $after): ?int
    {
        foreach ([$before, $after] as $ptr) {
            if (self::isBinary($phpcsFile, $ptr)) {
                return $ptr;
            }
        }

        $tokens = $phpcsFile->getTokens();
        $opener = $tokens[$after]['code'] === T_CLOSE_PARENTHESIS
            ? ($tokens[$after]['parenthesis_opener'] ?? null)
            : $before;
        if (!is_int($opener) || !self::isGrouping($phpcsFile, $opener)) {
            return null;
        }

        $closer = $tokens[$opener]['parenthesis_closer'];
        for ($i = ($opener + 1); $i < $closer; $i++) {
            if (self::isBinary($phpcsFile, $i)) {
                return $i;
            }

            $nestedCloser = TokenHelper::findCloser($tokens[$i]);
            if ($nestedCloser !== null && $nestedCloser > $i) {
                $i = $nestedCloser;
            }
        }

        $outside = [
            $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($opener - 1), null, true),
            $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($closer + 1), null, true),
        ];
        foreach ($outside as $ptr) {
            if ($ptr !== false && self::isBinary($phpcsFile, $ptr)) {
                return $ptr;
            }
        }

        return null;
    }
}
