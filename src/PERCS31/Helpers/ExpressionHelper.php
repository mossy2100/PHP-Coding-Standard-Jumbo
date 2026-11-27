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
     * Whether each expression read can be read, by file, token count and region, so a statement isn't parsed again for
     * each token in it.
     *
     * @var array<string, bool>
     */
    private static array $readable = [];

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
            // An array's, or a yield's key and value, as in `yield $key => $value`.
            if ($code === T_OPEN_SHORT_ARRAY || $code === T_YIELD) {
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

        $root = ExpressionParser::parse($phpcsFile, $region[0], $region[1]);

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

        // An arrow function found from its `fn` runs to the token that ends its body.
        $from = max($stackPtr, $skipTo ?? $stackPtr);
        $fromCloser = $tokens[$from]['scope_closer'] ?? null;
        if ($tokens[$from]['code'] === T_FN && is_int($fromCloser)) {
            $from = $fromCloser - 1;
        }

        $count = count($tokens);
        for ($after = ($from + 1); $after < $count; $after++) {
            $code = $tokens[$after]['code'];

            // An arrow function runs to the token that ends its body.
            $fnCloser = $tokens[$after]['scope_closer'] ?? null;
            if ($code === T_FN && is_int($fnCloser)) {
                $after = $fnCloser - 1;
                continue;
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
     * Find the outermost expression a token is part of, through any grouping parentheses, calls and arrays around it,
     * that can be read. So a call is laid out with the expression it's in, outermost first. Where the expression
     * around a call can't be read, as with a closure in it, each argument is an expression of its own.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @param bool $throughSplitCalls Whether to look through calls and arrays split over lines too, or only those on
     * one line, so an argument on its own line stays an expression of its own.
     * @return array{int, int, int, int}|null As findExpression() returns it.
     */
    public static function findOutermostExpression(File $phpcsFile, int $stackPtr, bool $throughSplitCalls): ?array
    {
        $tokens = $phpcsFile->getTokens();

        // An assignment is found from its value.
        if (self::isAssignmentPair($phpcsFile, $stackPtr)) {
            $stackPtr = (int) $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        }

        $candidates = [$stackPtr];
        $ptr = $stackPtr;
        while (true) {
            $container = TokenHelper::findContainer($phpcsFile, $ptr);
            $closer = $container === null ? null : TokenHelper::findCloser($tokens[$container]);
            if ($container === null || $closer === null) {
                break;
            }

            $isCall = self::isCallOpener($phpcsFile, $container)
                && ($throughSplitCalls || $tokens[$container]['line'] === $tokens[$closer]['line']);
            if (!$isCall && !self::isGrouping($phpcsFile, $container)) {
                break;
            }

            $ptr = $container;
            $candidates[] = $ptr;
        }

        $innermost = null;
        foreach (array_reverse($candidates) as $candidate) {
            // From an opening bracket, the expression's end is looked for after its closer.
            $isOpener = self::isCallOpener($phpcsFile, $candidate) || self::isGrouping($phpcsFile, $candidate);
            $region = self::findExpression(
                $phpcsFile,
                $candidate,
                $isOpener ? TokenHelper::findCloser($tokens[$candidate]) : null,
            );
            $region = $region === null ? null : self::widen($phpcsFile, $region);

            if ($region !== null && self::isReadable($phpcsFile, $region)) {
                return $region;
            }

            $innermost = $region;
        }

        return $innermost;
    }

    /**
     * Check whether an expression can be read: it isn't left alone, and no operand in it spans lines, apart from a call
     * or array.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @return bool True if it can.
     */
    private static function isReadable(File $phpcsFile, array $region): bool
    {
        $key = "{$phpcsFile->getFilename()}:{$phpcsFile->numTokens}:{$region[0]}:{$region[1]}";
        self::$readable[$key] ??= !self::isSkipped($phpcsFile, $region[0], $region[1], $region[2])
            && ExpressionParser::parse($phpcsFile, $region[0], $region[1]) !== null;

        return self::$readable[$key];
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
            // A closure's, `match`'s or anonymous class's body isn't laid out here, so what's in it doesn't matter.
            $condition = $tokens[$i]['scope_condition'] ?? null;
            $closer = $tokens[$i]['scope_closer'] ?? null;
            if (
                $tokens[$i]['code'] === T_OPEN_CURLY_BRACKET && is_int($condition) && is_int($closer)
                && in_array($tokens[$condition]['code'], [T_CLOSURE, T_MATCH, T_ANON_CLASS], true)
            ) {
                $i = $closer;
                continue;
            }

            $code = $tokens[$i]['code'];
            if (
                isset(Tokens::COMMENT_TOKENS[$code]) || in_array($code, [T_START_HEREDOC, T_START_NOWDOC], true)
                || ($code !== T_WHITESPACE && str_contains($tokens[$i]['content'], "\n"))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Widen an expression to the arm, ternary, arrow function or assignment it's part of, outermost, until nothing
     * changes.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @param bool $toTarget Whether an assignment's value is widened to its target, which it isn't when it's being
     * found from the target.
     * @return array{int, int, int, int} The widened region.
     */
    private static function widen(File $phpcsFile, array $region, bool $toTarget = true): array
    {
        do {
            $previous = $region;
            $region = self::widenToArm($phpcsFile, $region) ?? $region;
            $region = self::widenToTernary($phpcsFile, $region) ?? $region;
            $region = self::widenToArrowFunction($phpcsFile, $region) ?? $region;
            $region = self::widenToAssignment($phpcsFile, $region, $toTarget) ?? $region;
            $region = self::widenToEchoList($phpcsFile, $region) ?? $region;
        } while ($region !== $previous);

        return $region;
    }

    /**
     * Check whether an expression is a ternary's condition or value.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the token that ends the expression before it, or -1.
     * @param int $after The position of the token that ends it after it.
     * @return bool True if it is.
     */
    public static function isInTernary(File $phpcsFile, int $before, int $after): bool
    {
        $tokens = $phpcsFile->getTokens();
        $ternary = [T_INLINE_THEN, T_INLINE_ELSE];

        return in_array($tokens[$after]['code'] ?? null, $ternary, true)
            || ($before >= 0 && in_array($tokens[$before]['code'], $ternary, true));
    }

    /**
     * Check whether an expression is a whole ternary branch whose `?` or `:` starts a line.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the token that ends the expression before it, or -1.
     * @return bool True if it is.
     */
    public static function isTernaryBranchOnItsOwnLine(File $phpcsFile, int $before): bool
    {
        $tokens = $phpcsFile->getTokens();

        return $before >= 0 && in_array($tokens[$before]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true)
            && LayoutHelper::startsLine($phpcsFile, $before);
    }

    /**
     * Check whether a token is an assignment operator read as a pair, `$target = $value`: one with a target on its
     * left, with no operator, outside a function's parameters, where `=` gives a default value, and `declare()`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the token.
     * @return bool True if it is.
     */
    public static function isAssignmentPair(File $phpcsFile, int $ptr): bool
    {
        $tokens = $phpcsFile->getTokens();
        $code = $tokens[$ptr]['code'];
        if (!isset(Tokens::ASSIGNMENT_TOKENS[$code]) || $code === T_DOUBLE_ARROW) {
            return false;
        }

        $parentheses = array_keys($tokens[$ptr]['nested_parenthesis'] ?? []);
        $opener = end($parentheses);
        $owner = is_int($opener) ? ($tokens[$opener]['parenthesis_owner'] ?? null) : null;
        if (is_int($owner) && in_array($tokens[$owner]['code'], [T_FUNCTION, T_CLOSURE, T_FN, T_DECLARE], true)) {
            return false;
        }

        // One in a ternary's branch, as in `$a ? $b = 1 : $c = 2`, isn't read.
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($ptr - 1), null, true);
        $target = $previous === false ? null : self::findExpressionAround($phpcsFile, $previous);
        if (
            $target === null
            || ($target[2] >= 0 && in_array($tokens[$target[2]]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true))
        ) {
            return false;
        }

        for ($i = $target[0]; $i <= $target[1]; $i++) {
            if (self::isBinary($phpcsFile, $i) || in_array($tokens[$i]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true)) {
                return false;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        return true;
    }

    /**
     * Widen an expression that's an assignment's value to the whole assignment, or one that's its target.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @param bool $toTarget Whether a value is widened to its target.
     * @return array{int, int, int, int}|null The assignment's region, or null if the expression is neither.
     */
    private static function widenToAssignment(File $phpcsFile, array $region, bool $toTarget): ?array
    {
        [$start, $end, $before, $after] = $region;
        if ($toTarget && $before >= 0 && self::isAssignmentPair($phpcsFile, $before)) {
            $previous = (int) $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($before - 1), null, true);
            $target = self::findExpressionAround($phpcsFile, $previous);

            return $target === null ? null : [$target[0], $end, $target[2], $after];
        }

        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($after + 1), null, true);
        if ($next === false || !self::isAssignmentPair($phpcsFile, $after)) {
            return null;
        }

        $value = self::findExpressionAround($phpcsFile, $next);
        $value = $value === null ? null : self::widen($phpcsFile, $value, false);

        return $value === null ? null : [$start, $value[1], $before, $value[3]];
    }

    /**
     * Find the `echo` whose list of values an expression is one of, as `$b` is in `echo $a, $b;`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @return ?int The position of the `echo`, or null if the expression isn't one of its values.
     */
    private static function findEcho(File $phpcsFile, array $region): ?int
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $region[2]; $i >= 0; $i--) {
            $code = $tokens[$i]['code'];
            if ($code === T_ECHO) {
                return $i;
            }

            $opener = TokenHelper::findOpener($tokens[$i]);
            if ($opener !== null) {
                $i = $opener;
                continue;
            }

            if ($code !== T_COMMA && self::isBoundary($phpcsFile, $i)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Widen one of an `echo`'s values to its whole list of values, when it has more than one.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @return array{int, int, int, int}|null The list's region, or null if the expression isn't in one.
     */
    private static function widenToEchoList(File $phpcsFile, array $region): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $isValue = $region[2] >= 0 && in_array($tokens[$region[2]]['code'], [T_ECHO, T_COMMA], true);
        $echo = $isValue ? self::findEcho($phpcsFile, $region) : null;
        if ($echo === null) {
            return null;
        }

        $end = $region[3];
        while ($tokens[$end]['code'] === T_COMMA) {
            $next = self::findExpression(
                $phpcsFile,
                (int) $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($end + 1), null, true),
            );
            if ($next === null) {
                return null;
            }

            $end = $next[3];
        }

        $first = (int) $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($echo + 1), null, true);
        $last = (int) $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($end - 1), null, true);
        $list = [$first, $last, $echo, $end];

        return $list === $region || $phpcsFile->findNext(T_COMMA, $first, $last) === false ? null : $list;
    }

    /**
     * Widen an expression that's an arrow function's body to the whole arrow function.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @return array{int, int, int, int}|null The arrow function's region, or null if the expression isn't a body.
     */
    private static function widenToArrowFunction(File $phpcsFile, array $region): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $arrow = $region[2];
        if ($arrow < 0 || $tokens[$arrow]['code'] !== T_FN_ARROW) {
            return null;
        }

        $fn = $phpcsFile->findPrevious(T_FN, ($arrow - 1));
        $closer = $fn === false ? null : ($tokens[$fn]['scope_closer'] ?? null);
        if ($fn === false || ($tokens[$fn]['scope_opener'] ?? null) !== $arrow || !is_int($closer)) {
            return null;
        }

        return self::findExpression($phpcsFile, $fn, $closer - 1);
    }

    /**
     * Widen an expression that's a ternary's condition or value to the whole ternary.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @return array{int, int, int, int}|null The ternary's region, or null if the expression isn't part of one, or it's
     * part of a chain of two.
     */
    private static function widenToTernary(File $phpcsFile, array $region): ?array
    {
        $tokens = $phpcsFile->getTokens();
        [, , $before, $after] = $region;
        $question = match (true) {
            $tokens[$after]['code'] === T_INLINE_THEN => $after,
            $before >= 0 && $tokens[$before]['code'] === T_INLINE_THEN => $before,
            $before >= 0 && $tokens[$before]['code'] === T_INLINE_ELSE => self::findAtLevel($phpcsFile, $before, -1),
            default => null,
        };
        $colon = $question === null ? null : self::findAtLevel($phpcsFile, $question, 1);
        if ($question === null || $colon === null) {
            return null;
        }

        $conditionEnd = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($question - 1), null, true);
        $elseStart = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($colon + 1), null, true);
        $condition = $conditionEnd === false ? null : self::findExpressionAround($phpcsFile, $conditionEnd);
        $else = $elseStart === false ? null : self::findExpressionAround($phpcsFile, $elseStart);
        if ($condition === null || $else === null) {
            return null;
        }

        $ternaryTokens = [T_INLINE_THEN, T_INLINE_ELSE];
        $isChained = ($condition[2] >= 0 && in_array($tokens[$condition[2]]['code'], $ternaryTokens, true))
            || in_array($tokens[$else[3]]['code'], $ternaryTokens, true);

        return $isChained ? null : [$condition[0], $else[1], $condition[2], $else[3]];
    }

    /**
     * Find the expression a token is part of, from outside its brackets when it's a bracket itself.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the token.
     * @return array{int, int, int, int}|null As findExpression() returns it.
     */
    private static function findExpressionAround(File $phpcsFile, int $ptr): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $opener = TokenHelper::findOpener($tokens[$ptr]);
        if ($opener !== null) {
            return self::findExpression($phpcsFile, $opener, $ptr);
        }

        $closer = TokenHelper::findCloser($tokens[$ptr]);

        return self::findExpression($phpcsFile, $ptr, $closer !== null && $closer > $ptr ? $closer : null);
    }

    /**
     * Find where a token's brackets or body end, to skip over them when looking for commas or operators outside them.
     *
     * An arrow function's scope runs to the token that ends it, which may be the comma being looked for, so only its
     * parameters are skipped; its body has no comma or operator of the expression around it outside brackets.
     *
     * @param array<string, mixed> $token The token.
     * @param int $ptr Its position.
     * @return int The position of its closer, or its own position if it doesn't open anything.
     */
    public static function findNestedEnd(array $token, int $ptr): int
    {
        $closer = TokenHelper::findCloser($token);
        if ($closer === null && !in_array($token['code'], [T_FN, T_FN_ARROW], true)) {
            $closer = $token['scope_closer'] ?? null;
        }

        return is_int($closer) && $closer > $ptr ? $closer : $ptr;
    }

    /**
     * Find the other half of a ternary, the `:` after a `?` or the `?` before a `:`, outside any brackets between them.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the `?` or `:`.
     * @param int $direction 1 to look forward, for the `:`, or -1 to look back, for the `?`.
     * @return ?int The position, or null if the statement ends first.
     */
    private static function findAtLevel(File $phpcsFile, int $ptr, int $direction): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $wanted = $direction === 1 ? T_INLINE_ELSE : T_INLINE_THEN;
        for ($i = $ptr + $direction; isset($tokens[$i]); $i += $direction) {
            $code = $tokens[$i]['code'];
            if ($code === $wanted) {
                return $i;
            }

            if (in_array($code, [T_SEMICOLON, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                return null;
            }

            $partner = $direction === 1 ? TokenHelper::findCloser($tokens[$i]) : TokenHelper::findOpener($tokens[$i]);
            if ($partner !== null && ($partner - $i) * $direction > 0) {
                $i = $partner;
            } elseif (
                $direction === 1
                    ? TokenHelper::findOpener($tokens[$i]) !== null
                    : TokenHelper::findCloser($tokens[$i]) !== null
            ) {
                // A bracket closed before the other half is found: the ternary is inside it, and so is the other half.
                return null;
            }
        }

        return null;
    }

    /**
     * Widen an expression in a `match` arm with several conditions, a condition or the value, to the whole arm.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{int, int, int, int} $region The expression's region.
     * @return array{int, int, int, int}|null The arm's region, or null if the expression isn't in such an arm.
     */
    private static function widenToArm(File $phpcsFile, array $region): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $before = $region[2];
        $matches = array_keys($tokens[$region[0]]['conditions'], T_MATCH, true);
        $match = end($matches);
        $opener = is_int($match) ? ($tokens[$match]['scope_opener'] ?? null) : null;
        $closer = is_int($match) ? ($tokens[$match]['scope_closer'] ?? null) : null;
        $isInArm = $before >= 0
            && ($before === $opener || in_array($tokens[$before]['code'], [T_COMMA, T_MATCH_ARROW], true));
        if (
            !is_int($opener) || !is_int($closer) || !$isInArm
            || array_key_last($tokens[$region[0]]['conditions']) !== $match
        ) {
            return null;
        }

        foreach (MatchHelper::findArms($phpcsFile, $opener, $closer) as $arm) {
            $after = $arm['end'] ?? $closer;
            if ($region[0] < $arm['start'] || $region[0] > $after) {
                continue;
            }

            $last = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($after - 1), $arm['start'], true);
            $armBefore = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($arm['start'] - 1), null, true);
            $isSeveral = count(MatchHelper::findConditions($phpcsFile, $arm)) > 1;

            return $isSeveral && $last !== false && $armBefore !== false
                ? [$arm['start'], $last, $armBefore, $after]
                : null;
        }

        return null;
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
        $tokens = $phpcsFile->getTokens();
        foreach ([$before, $after] as $ptr) {
            if (in_array($tokens[$ptr]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true)) {
                return self::readsOperator($phpcsFile, $ptr);
            }
        }

        $operator = self::findOperatorBeside($phpcsFile, $before, $after);
        if ($operator !== null) {
            return self::readsOperator($phpcsFile, $operator);
        }

        // A line break after a comma in an `echo` list.
        if ($tokens[$before]['code'] === T_COMMA) {
            $value = self::findExpression($phpcsFile, $after);
            $list = $value === null ? null : self::widenToEchoList($phpcsFile, $value);

            return $list !== null && self::read($phpcsFile, $after, $list) !== null;
        }

        // A line break before a call's opening bracket, as in `new` then `Foo()`, but not after a keyword that ends an
        // expression, such as `return`.
        $opener = self::isBoundary($phpcsFile, $before) ? null : self::findCalleeOpener($phpcsFile, $after);
        $region = $opener === null ? null : self::findExpressionAround($phpcsFile, $opener);

        return $region !== null && self::read($phpcsFile, (int) $opener, $region) !== null;
    }

    /**
     * Check whether ExpressionFormat, in strict mode, reads the expression an operator is in: a binary or assignment
     * operator, or a ternary's `?` or `:`, which is read from the value after it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $operator The position of the operator.
     * @return bool True if it does.
     */
    private static function readsOperator(File $phpcsFile, int $operator): bool
    {
        $ptr = $operator;
        if (in_array($phpcsFile->getTokens()[$operator]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true)) {
            $skip = [...array_keys(Tokens::EMPTY_TOKENS), T_INLINE_ELSE];
            $ptr = (int) $phpcsFile->findNext($skip, ($operator + 1), null, true);
        }

        $region = self::findOutermostExpression($phpcsFile, $ptr, true);

        return $region !== null && self::read($phpcsFile, $ptr, $region) !== null;
    }

    /**
     * Find the opening bracket of the call whose callee a token is in, as `Foo` is in `new Foo()`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the token.
     * @return ?int The position of the bracket, or null if the token isn't in a callee.
     */
    private static function findCalleeOpener(File $phpcsFile, int $ptr): ?int
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $ptr; $i < count($tokens); $i++) {
            if (self::isCallOpener($phpcsFile, $i)) {
                return $i;
            }

            if (self::isBoundary($phpcsFile, $i) || self::isBinary($phpcsFile, $i)) {
                return null;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        return null;
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
            if (self::isBinary($phpcsFile, $ptr) || self::isAssignmentPair($phpcsFile, $ptr)) {
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
            $isTernary = $ptr !== false
                && in_array($phpcsFile->getTokens()[$ptr]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true);
            if (
                $ptr !== false
                && ($isTernary || self::isBinary($phpcsFile, $ptr) || self::isAssignmentPair($phpcsFile, $ptr))
            ) {
                return $ptr;
            }
        }

        return null;
    }
}
