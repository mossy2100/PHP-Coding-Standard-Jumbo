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
            && self::parse($phpcsFile, $region[0], $region[1]) !== null;

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
        $arrowFunction = self::parseArrowFunction($phpcsFile, $start, $end);
        if ($arrowFunction !== false) {
            return $arrowFunction;
        }

        $assignment = self::findAssignment($phpcsFile, $start, $end);
        if ($assignment !== null) {
            return self::parseAssignment($phpcsFile, $start, $end, $assignment);
        }

        $arm = self::findArmArrow($phpcsFile, $start, $end);
        if ($arm !== null) {
            return self::parseArm($phpcsFile, $start, $end, $arm);
        }

        $questions = self::findTernaryTokens($phpcsFile, $start, $end);
        if ($questions !== []) {
            return count($questions) === 2 ? self::parseTernary($phpcsFile, $start, $end, ...$questions) : null;
        }

        $operators = [];
        for ($i = $start; $i <= $end; $i++) {
            // An arrow function runs to the end, and its body's operators are its own.
            if ($tokens[$i]['code'] === T_FN) {
                break;
            }

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
     * Find an assignment's operator outside any brackets, the first if there are several, as in `$a = $b = 1`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return ?int The position of the operator, or null if there's none.
     */
    private static function findAssignment(File $phpcsFile, int $start, int $end): ?int
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['code'] === T_FN) {
                return null;
            }

            if (self::isAssignmentPair($phpcsFile, $i)) {
                return $i;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        return null;
    }

    /**
     * Parse an assignment, as a pair: its target is the key, and its value the value.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the target's first token.
     * @param int $end The position of the value's last token.
     * @param int $operator The position of the assignment operator.
     * @return ?ExpressionNode The assignment, or null if it can't be read.
     */
    private static function parseAssignment(File $phpcsFile, int $start, int $end, int $operator): ?ExpressionNode
    {
        $targetEnd = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($operator - 1), $start, true);
        $valueStart = $phpcsFile->findNext(T_WHITESPACE, ($operator + 1), ($end + 1), true);
        $target = self::parse($phpcsFile, $start, $targetEnd);
        $value = $valueStart === false ? null : self::parse($phpcsFile, $valueStart, $end);
        if ($target === null || $value === null) {
            return null;
        }

        $length = mb_strlen($phpcsFile->getTokens()[$operator]['content']);

        return new ExpressionNode(
            ExpressionNode::LEVEL,
            $start,
            $end,
            $target->flatLength + $length + 2 + $value->flatLength,
            [$target, $value],
            [$operator],
        );
    }

    /**
     * Parse a chain: an object, then two or more links after `->` or `?->` outside any brackets. A static call or
     * property before the first arrow is part of the object, and a `::` or an index after a link is part of the link.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the operand's first token.
     * @param int $end The position of its last token.
     * @return ExpressionNode|false|null The chain, false if the operand isn't one, or null if it can't be read.
     */
    private static function parseChain(File $phpcsFile, int $start, int $end): ExpressionNode|false|null
    {
        $tokens = $phpcsFile->getTokens();
        $arrows = [];
        for ($i = $start; $i <= $end; $i++) {
            if (in_array($tokens[$i]['code'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $arrows[] = $i;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        if (count($arrows) < 2) {
            return false;
        }

        $headEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($arrows[0] - 1), $start, true);
        $head = $headEnd === false ? null : self::parse($phpcsFile, $start, $headEnd);
        if ($head === null) {
            return null;
        }

        $children = [$head];
        $flatLength = $head->flatLength;
        foreach ($arrows as $index => $arrow) {
            $linkStart = $phpcsFile->findNext(T_WHITESPACE, ($arrow + 1), ($end + 1), true);
            $linkEnd = $phpcsFile->findPrevious(T_WHITESPACE, (($arrows[$index + 1] ?? ($end + 1)) - 1), $arrow, true);
            $link = $linkStart === false || $linkEnd === false || $linkEnd < $linkStart
                ? null
                : self::parse($phpcsFile, $linkStart, $linkEnd);
            if ($link === null) {
                return null;
            }

            $children[] = $link;
            $flatLength += mb_strlen($tokens[$arrow]['content']) + $link->flatLength;
        }

        return new ExpressionNode(ExpressionNode::CHAIN, $start, $end, $flatLength, $children, $arrows);
    }

    /**
     * Parse an arrow function, as a pair: its signature, which must be on one line, is the key, and its body the value.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return ExpressionNode|false|null The arrow function, false if the expression isn't one, or null if it can't be
     * read.
     */
    private static function parseArrowFunction(File $phpcsFile, int $start, int $end): ExpressionNode|false|null
    {
        $tokens = $phpcsFile->getTokens();
        $fn = $tokens[$start]['code'] === T_STATIC
            ? $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($start + 1), $end, true)
            : $start;
        $arrow = $fn === false ? null : ($tokens[$fn]['scope_opener'] ?? null);
        $closer = $fn === false ? null : ($tokens[$fn]['scope_closer'] ?? null);
        if ($fn === false || $tokens[$fn]['code'] !== T_FN || !is_int($arrow) || !is_int($closer)) {
            return false;
        }

        $bodyEnd = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($closer - 1), $arrow, true);
        $bodyStart = $phpcsFile->findNext(T_WHITESPACE, ($arrow + 1), ($end + 1), true);
        $signatureEnd = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($arrow - 1), $start, true);
        $signature = $phpcsFile->getTokensAsString($start, ($signatureEnd - $start + 1));
        if ($bodyEnd !== $end) {
            return false;
        }

        $body = $bodyStart === false ? null : self::parse($phpcsFile, $bodyStart, $end);
        if ($body === null || str_contains($signature, "\n")) {
            return null;
        }

        $key = new ExpressionNode(ExpressionNode::OPERAND, $start, $signatureEnd, mb_strlen($signature));

        return new ExpressionNode(
            ExpressionNode::LEVEL,
            $start,
            $end,
            $key->flatLength + 4 + $body->flatLength,
            [$key, $body],
            [$arrow],
        );
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
     * Find a ternary's `?` and `:` outside any brackets.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return list<int> The positions of the `?` and the `:`, nothing if there's no ternary, or anything else, such
     * as a chain of two, which isn't read.
     */
    private static function findTernaryTokens(File $phpcsFile, int $start, int $end): array
    {
        $tokens = $phpcsFile->getTokens();
        $found = [];
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['code'] === T_FN) {
                break;
            }

            // A pair's `=>`, or an assignment's operator, binds more loosely than a ternary, so the ternary is in the
            // pair's value.
            $isArrow = in_array($tokens[$i]['code'], [T_DOUBLE_ARROW, T_MATCH_ARROW], true)
                && self::isArrowOperator($phpcsFile, $i);
            if ($isArrow || self::isAssignmentPair($phpcsFile, $i)) {
                return [];
            }

            if (in_array($tokens[$i]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true)) {
                $found[] = $i;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        $isPair = count($found) === 2 && $tokens[$found[0]]['code'] === T_INLINE_THEN;

        return $found === [] || $isPair ? $found : [-1];
    }

    /**
     * Parse a ternary into its condition and values.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the ternary's first token.
     * @param int $end The position of its last token.
     * @param int $question The position of its `?`.
     * @param int $colon The position of its `:`.
     * @return ?ExpressionNode The ternary, or null if a part can't be read.
     */
    private static function parseTernary(
        File $phpcsFile,
        int $start,
        int $end,
        int $question,
        int $colon,
    ): ?ExpressionNode {
        $tokens = $phpcsFile->getTokens();
        $conditionEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($question - 1), $start, true);
        $elseStart = $phpcsFile->findNext(T_WHITESPACE, ($colon + 1), ($end + 1), true);
        $isShort = $phpcsFile->findNext(T_WHITESPACE, ($question + 1), $colon, true) === false;
        $thenStart = $isShort ? false : $phpcsFile->findNext(T_WHITESPACE, ($question + 1), $colon, true);
        $thenEnd = $isShort ? false : $phpcsFile->findPrevious(T_WHITESPACE, ($colon - 1), $question, true);
        if (
            $conditionEnd === false || $elseStart === false
            || (!$isShort && ($thenStart === false || $thenEnd === false))
        ) {
            return null;
        }

        $parts = [[$start, $conditionEnd], ...($isShort ? [] : [[$thenStart, $thenEnd]]), [$elseStart, $end]];
        $children = [];
        foreach ($parts as [$partStart, $partEnd]) {
            $child = self::parse($phpcsFile, (int) $partStart, (int) $partEnd);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
        }

        // On one line: the parts, with ` ? ` and ` : ` between them, or ` ?: ` for a short ternary.
        $flatLength = array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + ($isShort ? 4 : 6);

        return new ExpressionNode(ExpressionNode::TERNARY, $start, $end, $flatLength, $children, [$question, $colon]);
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
    private static function findNestedEnd(array $token, int $ptr): int
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
     * Find the `=>` of a `match` arm with several conditions, when the expression is that whole arm.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return ?int The position of the `=>`, or null if there isn't one outside any brackets, or the arm has one
     * condition, whose `=>` is read as an operator.
     */
    private static function findArmArrow(File $phpcsFile, int $start, int $end): ?int
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['code'] === T_FN) {
                return null;
            }

            if ($tokens[$i]['code'] === T_MATCH_ARROW) {
                return self::isArrowOperator($phpcsFile, $i) ? null : $i;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        return null;
    }

    /**
     * Parse a `match` arm with several conditions into a pair, whose key is the list of conditions.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the arm's first token.
     * @param int $end The position of its last token.
     * @param int $arrow The position of its `=>`.
     * @return ?ExpressionNode The pair, or null if a part can't be read.
     */
    private static function parseArm(File $phpcsFile, int $start, int $end, int $arrow): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $conditionsEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($arrow - 1), $start, true);
        $valueStart = $phpcsFile->findNext(T_WHITESPACE, ($arrow + 1), ($end + 1), true);
        if ($conditionsEnd === false || $valueStart === false) {
            return null;
        }

        // The commas between the conditions, outside any brackets.
        $commas = [];
        for ($i = $start; $i <= $conditionsEnd; $i++) {
            if ($tokens[$i]['code'] === T_COMMA) {
                $commas[] = $i;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        $trailingComma = $commas !== [] && end($commas) === $conditionsEnd ? array_pop($commas) : null;
        $children = [];
        $conditionStart = $start;
        foreach ([...$commas, ($trailingComma ?? $conditionsEnd + 1)] as $boundary) {
            $last = $phpcsFile->findPrevious(T_WHITESPACE, ($boundary - 1), $conditionStart, true);
            $child = $last === false ? null : self::parse($phpcsFile, $conditionStart, $last);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
            $conditionStart = (int) $phpcsFile->findNext(T_WHITESPACE, ($boundary + 1), null, true);
        }

        $value = self::parse($phpcsFile, $valueStart, $end);
        if ($value === null || count($children) < 2) {
            return null;
        }

        $listLength = array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + 2 * (count($children) - 1);
        $list = new ExpressionNode(
            kind: ExpressionNode::LIST,
            start: $start,
            end: $trailingComma ?? $children[count($children) - 1]->end,
            flatLength: $listLength,
            children: $children,
            separators: $commas,
            trailingComma: $trailingComma,
        );

        return new ExpressionNode(
            ExpressionNode::LEVEL,
            $start,
            $end,
            $listLength + 4 + $value->flatLength,
            [$list, $value],
            [$arrow],
        );
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
        $loosest
            = min(array_map(static fn(int $operator): int => $precedences[$tokens[$operator]['code']], $operators));

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

            // An operand can be a ternary, as a pair's value can, which only parse() reads.
            $child = $segmentOperators === []
                ? self::parse($phpcsFile, $segmentStart, $segmentEnd)
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
            $chain = self::parseChain($phpcsFile, $start, $end);
            if ($chain !== false) {
                return $chain;
            }

            // A call, an array or a body can span lines; any other operand that does is left alone.
            $call = self::parseCall($phpcsFile, $start, $end) ?? self::parseBody($phpcsFile, $start, $end);
            $text = $phpcsFile->getTokensAsString($start, ($end - $start + 1));
            if ($call !== null || str_contains($text, "\n")) {
                return $call;
            }

            return new ExpressionNode(ExpressionNode::OPERAND, $start, $end, mb_strlen($text));
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
     * Parse an operand that's a closure, `match` or anonymous class, whose body isn't laid out here.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the operand's first token.
     * @param int $end The position of its last token.
     * @return ?ExpressionNode The body, or null if the operand isn't one, or its first line spans lines.
     */
    private static function parseBody(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $keyword = $tokens[$start]['code'] === T_STATIC
            ? $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($start + 1), $end, true)
            : $start;
        if ($keyword !== false && $tokens[$keyword]['code'] === T_NEW) {
            $keyword = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($keyword + 1), $end, true);
            $keyword = $keyword !== false && $tokens[$keyword]['code'] === T_ANON_CLASS ? $keyword : false;
        }

        $opener = $keyword === false ? null : ($tokens[$keyword]['scope_opener'] ?? null);
        if (
            $keyword === false || !in_array($tokens[$keyword]['code'], [T_CLOSURE, T_MATCH, T_ANON_CLASS], true)
            || ($tokens[$keyword]['scope_closer'] ?? null) !== $end || !is_int($opener)
        ) {
            return null;
        }

        $head = $phpcsFile->getTokensAsString($start, ($opener - $start + 1));
        if (str_contains($head, "\n")) {
            return null;
        }

        return new ExpressionNode(
            kind: ExpressionNode::BODY,
            start: $start,
            end: $end,
            flatLength: ExpressionNode::BODY_LENGTH,
            headLength: mb_strlen($head),
            isClosure: $tokens[$keyword]['code'] === T_CLOSURE,
        );
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

        // What comes before the bracket, such as a chain, must be on one line.
        $callee = $phpcsFile->getTokensAsString($start, ($opener - $start));
        if (str_contains($callee, "\n")) {
            return null;
        }

        // The commas between the arguments, outside any brackets or bodies in them.
        $commas = [];
        for ($i = ($opener + 1); $i < $end; $i++) {
            if ($tokens[$i]['code'] === T_COMMA) {
                $commas[] = $i;
            }

            $i = self::findNestedEnd($tokens[$i], $i);
        }

        $children = [];
        $separators = [];
        $trailingComma = null;
        $argumentStart = $opener;
        foreach ([...$commas, $end] as $boundary) {
            $first = $phpcsFile->findNext(T_WHITESPACE, ($argumentStart + 1), $boundary, true);
            // A destructuring list's skipped element, as in `[$a, , $c] = $list`, isn't read.
            if ($first === false && $boundary !== $end) {
                return null;
            }

            if ($first === false) {
                // Nothing after the last comma: a trailing comma.
                if ($commas !== []) {
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

        // On one line: what comes before the bracket, the bracket, the arguments with ", " between, and the closer. A
        // call with no arguments may still be split over lines, with nothing but whitespace between its brackets.
        $flatLength = mb_strlen($callee) + 2
            + array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + 2 * max(0, count($children) - 1);
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
            if ($ptr !== false && self::isBinary($phpcsFile, $ptr)) {
                return $ptr;
            }
        }

        return null;
    }
}
