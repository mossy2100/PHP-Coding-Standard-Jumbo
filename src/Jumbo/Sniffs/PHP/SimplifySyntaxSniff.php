<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PHP;

use Jumbo\Helpers\ExpressionHelper;
use Jumbo\Helpers\FunctionCallHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires the simpler of two ways to write the same thing, where both behave the same.
 *
 * PER Coding Style 3.1 has no rules for these, so this is an extra rule. Each is fixed:
 * - CanSimplify: a `for` loop with only a condition, as a `while` loop. From
 *   Generic.CodeAnalysis.ForLoopShouldBeWhileLoop, which has no fix.
 * - Infinite: `for (;;)` as `while (true)`, from php-cs-fixer's `empty_loop_condition`.
 * - ConsecutiveIssets: `isset($a) && isset($b)` as `isset($a, $b)`, from php-cs-fixer's
 *   `combine_consecutive_issets`.
 * - ConsecutiveUnsets: `unset($a); unset($b);` as `unset($a, $b);`, from php-cs-fixer's `combine_consecutive_unsets`.
 * - NullsafeThis: `$this?->` as `$this->`, as `$this` is never null. From php-cs-fixer's
 *   `no_useless_nullsafe_operator`.
 * - BinaryString: a string without the `b` prefix, which does nothing. From Laravel Pint's `no_binary_string`.
 * - UnneededBraces: a block on its own, not a control structure's, without its braces, as they don't make a scope.
 *   From Laravel Pint's `no_unneeded_braces`.
 * - UselessReturn: no `return;` as a function's last statement. From Laravel Pint's `no_useless_return`.
 *
 * A fix never deletes a comment: where one is in the code a fix would remove, it's reported, not fixed.
 */
class SimplifySyntaxSniff implements Sniff
{
    public const string CODE_CAN_SIMPLIFY = 'CanSimplify';

    public const string CODE_INFINITE = 'Infinite';

    public const string CODE_CONSECUTIVE_ISSETS = 'ConsecutiveIssets';

    public const string CODE_CONSECUTIVE_UNSETS = 'ConsecutiveUnsets';

    public const string CODE_NULLSAFE_THIS = 'NullsafeThis';

    public const string CODE_BINARY_STRING = 'BinaryString';

    public const string CODE_UNNEEDED_BRACES = 'UnneededBraces';

    public const string CODE_USELESS_RETURN = 'UselessReturn';

    /**
     * The tokens a statement can start after.
     *
     * @var list<int|string>
     */
    private const array STATEMENT_STARTS = [
        T_SEMICOLON,
        T_OPEN_CURLY_BRACKET,
        T_CLOSE_CURLY_BRACKET,
        T_OPEN_TAG,
        T_COLON,
    ];

    /**
     * The closing brackets, which end an expression.
     *
     * @var list<int|string>
     */
    private const array CLOSERS = [
        T_CLOSE_PARENTHESIS,
        T_CLOSE_SQUARE_BRACKET,
        T_CLOSE_SHORT_ARRAY,
        T_CLOSE_CURLY_BRACKET,
    ];

    /**
     * The declarations that a block's braces make conditional, so they aren't removed.
     *
     * @var list<int|string>
     */
    private const array DECLARATIONS = [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [
            T_FOR,
            T_ISSET,
            T_UNSET,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_CONSTANT_ENCAPSED_STRING,
            T_BINARY_CAST,
            T_NONE,
            T_START_HEREDOC,
            T_START_NOWDOC,
            T_OPEN_CURLY_BRACKET,
            T_RETURN,
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
        switch ($phpcsFile->getTokens()[$stackPtr]['code']) {
            case T_FOR:
                $this->checkFor($phpcsFile, $stackPtr);
                break;
            case T_ISSET:
                $this->checkIssets($phpcsFile, $stackPtr);
                break;
            case T_UNSET:
                $this->checkUnsets($phpcsFile, $stackPtr);
                break;
            case T_NULLSAFE_OBJECT_OPERATOR:
                $this->checkNullsafe($phpcsFile, $stackPtr);
                break;
            case T_OPEN_CURLY_BRACKET:
                $this->checkBraces($phpcsFile, $stackPtr);
                break;
            case T_RETURN:
                $this->checkReturn($phpcsFile, $stackPtr);
                break;
            default:
                $this->checkBinaryString($phpcsFile, $stackPtr);
                break;
        }
    }

    // region For loops

    /**
     * Report a `for` loop with only a condition, or with none, and fix it to a `while` loop.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `for` keyword.
     */
    private function checkFor(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $opener = $tokens[$stackPtr]['parenthesis_opener'] ?? null;
        $closer = $tokens[$stackPtr]['parenthesis_closer'] ?? null;
        if (!is_int($opener) || !is_int($closer)) {
            return;
        }

        // The semicolons between the three parts, and whether each part has code or a comment.
        $semicolons = [];
        $hasCode = [false, false, false];
        $hasComment = [false, false, false];
        $hasComma = false;
        for ($i = ($opener + 1); $i < $closer; $i++) {
            $code = $tokens[$i]['code'];
            $part = min(count($semicolons), 2);
            if ($code === T_SEMICOLON) {
                $semicolons[] = $i;
            } elseif (isset(Tokens::EMPTY_TOKENS[$code])) {
                $hasComment[$part] = $hasComment[$part] || $code !== T_WHITESPACE;
            } else {
                $hasCode[$part] = true;
                $hasComma = $hasComma || $code === T_COMMA;
                $nestedCloser = $tokens[$i]['parenthesis_closer'] ?? $tokens[$i]['bracket_closer'] ?? null;
                $i = is_int($nestedCloser) && $nestedCloser > $i ? $nestedCloser : $i;
            }
        }

        // A condition of several expressions, as in `for (; $a, $b;)`, can't be a while loop's.
        if (count($semicolons) !== 2 || $hasCode[0] || $hasCode[2] || $hasComma) {
            return;
        }

        $isInfinite = !$hasCode[1];
        $fixable = !$hasComment[0] && !$hasComment[2] && (!$isInfinite || !$hasComment[1]);
        $error = $isInfinite
            ? 'An infinite loop must be while (true), not for (;;)'
            : 'A for loop with only a condition must be a while loop';
        $code = $isInfinite ? self::CODE_INFINITE : self::CODE_CAN_SIMPLIFY;
        if (!$fixable) {
            $phpcsFile->addError($error, $stackPtr, $code);
            return;
        }

        if (!$phpcsFile->addFixableError($error, $stackPtr, $code)) {
            return;
        }

        // Keep the condition, without the whitespace around it.
        $first = $phpcsFile->findNext(T_WHITESPACE, ($semicolons[0] + 1), $semicolons[1], true);
        $last = $phpcsFile->findPrevious(T_WHITESPACE, ($semicolons[1] - 1), $semicolons[0], true);
        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($stackPtr, 'while');
        for ($i = ($opener + 1); $i < $closer; $i++) {
            if ($first === false || $last === false || $i < $first || $i > $last) {
                $phpcsFile->fixer->replaceToken($i, '');
            }
        }

        if ($isInfinite) {
            $phpcsFile->fixer->addContentBefore($closer, 'true');
        }

        $scopeCloser = $tokens[$stackPtr]['scope_closer'] ?? null;
        if (is_int($scopeCloser) && $tokens[$scopeCloser]['code'] === T_ENDFOR) {
            $phpcsFile->fixer->replaceToken($scopeCloser, 'endwhile');
        }

        $phpcsFile->fixer->endChangeset();
    }

    // endregion

    // region isset() and unset()

    /**
     * Report `isset()` calls joined by `&&`, from the first of them, and fix them to one call.
     *
     * Only calls that are whole operands of `&&` are joined, so `!isset($a) && isset($b)` and `isset($a) && isset($b)
     * === $c` are left alone.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `isset` keyword.
     */
    private function checkIssets(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        if ($previous !== false && !$this->isLooseNeighbour($phpcsFile, $previous)) {
            return;
        }

        // A call after `&&` that joins with the one before it is reported with that one.
        $before = $previous === false ? false : $phpcsFile->findPrevious(
            Tokens::EMPTY_TOKENS,
            ($previous - 1),
            null,
            true,
        );
        if ($before !== false && $tokens[$previous]['code'] === T_BOOLEAN_AND) {
            $earlier = $this->findCallBefore($phpcsFile, $before, T_ISSET);
            $beforeEarlier = $earlier === null ? false : $phpcsFile->findPrevious(
                Tokens::EMPTY_TOKENS,
                ($earlier - 1),
                null,
                true,
            );
            $isEarlierJoined = $beforeEarlier === false || $this->isLooseNeighbour($phpcsFile, $beforeEarlier);
            if ($earlier !== null && $isEarlierJoined) {
                return;
            }
        }

        $chain = [$stackPtr];
        $next = $this->findNextIsset($phpcsFile, $stackPtr);
        while ($next !== null) {
            $chain[] = $next;
            $next = $this->findNextIsset($phpcsFile, $next);
        }

        $lastCloser = $this->closer($phpcsFile, end($chain));
        $after = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($lastCloser + 1), null, true);
        if ($after === false || !$this->isLooseNeighbour($phpcsFile, $after)) {
            array_pop($chain);
        }

        $this->reportChain(
            $phpcsFile,
            $chain,
            'Consecutive isset() calls joined by && must be one isset() call',
            self::CODE_CONSECUTIVE_ISSETS,
        );
    }

    /**
     * Report `unset()` statements that follow each other directly, from the first of them, and fix them to one.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `unset` keyword.
     */
    private function checkUnsets(File $phpcsFile, int $stackPtr): void
    {
        if (!$this->isUnsetStatement($phpcsFile, $stackPtr)) {
            return;
        }

        // A statement straight after another is reported with that one.
        $tokens = $phpcsFile->getTokens();
        $previous = $phpcsFile->findPrevious(T_WHITESPACE, ($stackPtr - 1), null, true);
        $before = $previous === false ? false : $phpcsFile->findPrevious(T_WHITESPACE, ($previous - 1), null, true);
        if ($before !== false && $tokens[$previous]['code'] === T_SEMICOLON) {
            $earlier = $this->findCallBefore($phpcsFile, $before, T_UNSET);
            if ($earlier !== null && $this->isUnsetStatement($phpcsFile, $earlier)) {
                return;
            }
        }

        $chain = [$stackPtr];
        while (true) {
            $semicolon = $phpcsFile->findNext(T_WHITESPACE, ($this->closer($phpcsFile, end($chain)) + 1), null, true);
            $next = $semicolon === false ? false : $phpcsFile->findNext(T_WHITESPACE, ($semicolon + 1), null, true);
            if ($next === false || $tokens[$next]['code'] !== T_UNSET || !$this->isUnsetStatement($phpcsFile, $next)) {
                break;
            }

            $chain[] = $next;
        }

        $this->reportChain(
            $phpcsFile,
            $chain,
            'Consecutive unset() statements must be one unset() statement',
            self::CODE_CONSECUTIVE_UNSETS,
        );
    }

    /**
     * Report a chain of `isset()` or `unset()` calls that can be one, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param list<int> $chain The positions of the calls' keywords.
     * @param string $error The message.
     * @param string $code The code.
     */
    private function reportChain(File $phpcsFile, array $chain, string $error, string $code): void
    {
        if (count($chain) < 2) {
            return;
        }

        // The code between each call's closing parenthesis and the next call's opening one, which the fix replaces.
        $tokens = $phpcsFile->getTokens();
        $joins = [];
        $hasComment = false;
        foreach (array_slice($chain, 1) as $index => $keywordPtr) {
            $join = [$this->closer($phpcsFile, $chain[$index]), $this->opener($phpcsFile, $keywordPtr)];
            $joins[] = $join;
            $hasComment = $hasComment || $phpcsFile->findNext(Tokens::COMMENT_TOKENS, $join[0], $join[1]) !== false;
        }

        $stackPtr = $chain[0];
        if ($hasComment) {
            $phpcsFile->addError($error, $stackPtr, $code);
            return;
        }

        if (!$phpcsFile->addFixableError($error, $stackPtr, $code)) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        foreach ($joins as [$closer, $opener]) {
            for ($i = $closer; $i < $opener; $i++) {
                $phpcsFile->fixer->replaceToken($i, '');
            }

            // A trailing comma in the call before already separates the arguments.
            $lastArgument = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($closer - 1), null, true);
            $hasTrailingComma = $lastArgument !== false && $tokens[$lastArgument]['code'] === T_COMMA;
            $phpcsFile->fixer->replaceToken($opener, $hasTrailingComma ? ' ' : ', ');
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Find the `isset()` call joined to the end of one by `&&`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $issetPtr The position of the first call's `isset` keyword.
     * @return ?int The position of the next call's `isset` keyword, or null if there isn't one.
     */
    private function findNextIsset(File $phpcsFile, int $issetPtr): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $operator = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($this->closer($phpcsFile, $issetPtr) + 1), null, true);
        if ($operator === false || $tokens[$operator]['code'] !== T_BOOLEAN_AND) {
            return null;
        }

        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($operator + 1), null, true);

        return $next !== false && $tokens[$next]['code'] === T_ISSET ? $next : null;
    }

    /**
     * Find the `isset()` or `unset()` call that a closing parenthesis ends.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $closer The position of the parenthesis.
     * @param int|string $keyword The keyword's token code.
     * @return ?int The position of the keyword, or null if the parenthesis doesn't end such a call.
     */
    private function findCallBefore(File $phpcsFile, int $closer, int|string $keyword): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $opener = $tokens[$closer]['parenthesis_opener'] ?? null;
        if ($tokens[$closer]['code'] !== T_CLOSE_PARENTHESIS || !is_int($opener)) {
            return null;
        }

        $name = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($opener - 1), null, true);

        return $name !== false && $tokens[$name]['code'] === $keyword ? $name : null;
    }

    /**
     * Check whether an `unset()` call is a statement of its own, ended by a semicolon.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $unsetPtr The position of the `unset` keyword.
     * @return bool True if it is.
     */
    private function isUnsetStatement(File $phpcsFile, int $unsetPtr): bool
    {
        $closer = $this->closer($phpcsFile, $unsetPtr);
        $next = $phpcsFile->findNext(T_WHITESPACE, ($closer + 1), null, true);

        return $next !== false && $phpcsFile->getTokens()[$next]['code'] === T_SEMICOLON
            && FunctionCallHelper::isStatement($phpcsFile, $unsetPtr, $closer);
    }

    /**
     * Check whether a token beside an operand binds no tighter than `&&`, so the operand is a whole operand of a `&&`
     * next to it: an operator as loose as `&&` or looser, a bracket, or a token that ends an expression.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the token.
     * @return bool True if it does.
     */
    private function isLooseNeighbour(File $phpcsFile, int $ptr): bool
    {
        $code = $phpcsFile->getTokens()[$ptr]['code'];
        $precedences = ExpressionHelper::precedences();
        if (isset($precedences[$code])) {
            return $precedences[$code] <= $precedences[T_BOOLEAN_AND];
        }

        return in_array($code, self::CLOSERS, true) || ExpressionHelper::isBoundary($phpcsFile, $ptr);
    }

    /**
     * Get a call's opening parenthesis.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $keywordPtr The position of the keyword.
     * @return int The position of the parenthesis.
     */
    private function opener(File $phpcsFile, int $keywordPtr): int
    {
        return FunctionCallHelper::opener($phpcsFile, $keywordPtr);
    }

    /**
     * Get a call's closing parenthesis.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $keywordPtr The position of the keyword.
     * @return int The position of the parenthesis.
     */
    private function closer(File $phpcsFile, int $keywordPtr): int
    {
        $tokens = $phpcsFile->getTokens();

        return (int) ($tokens[$this->opener($phpcsFile, $keywordPtr)]['parenthesis_closer'] ?? $keywordPtr);
    }

    // endregion

    // region Nullsafe operator and binary strings

    /**
     * Report `$this?->`, and fix it to `$this->`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `?->`.
     */
    private function checkNullsafe(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $variable = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        $before = $variable === false ? false : $phpcsFile->findPrevious(
            Tokens::EMPTY_TOKENS,
            ($variable - 1),
            null,
            true,
        );
        if (
            $variable === false || $tokens[$variable]['content'] !== '$this'
            || ($before !== false && in_array(
                $tokens[$before]['code'],
                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                true,
            ))
        ) {
            return;
        }

        $error = '$this is never null, so it needs -> not ?->';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_NULLSAFE_THIS)) {
            $phpcsFile->fixer->replaceToken($stackPtr, '->');
        }
    }

    /**
     * Report a string, heredoc or nowdoc with the `b` prefix, and fix it.
     *
     * PHP_CodeSniffer reads the prefix of `b"abc"` as a `(binary)` cast before the string, and of `b"$a"` as an
     * unknown token, after which the file isn't read right. Fixing that token lets it be read again.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     */
    private function checkBinaryString(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $content = $tokens[$stackPtr]['content'];
        $isPrefix = match ($tokens[$stackPtr]['code']) {
            T_BINARY_CAST => strtolower($content) === 'b'
                && ($tokens[$stackPtr + 1]['code'] ?? null) === T_CONSTANT_ENCAPSED_STRING,
            T_NONE => strtolower($content) === 'b"',
            default => in_array($content[0], ['b', 'B'], true),
        };
        if (!$isPrefix) {
            return;
        }

        $error = 'The b prefix on a string does nothing, so it must be removed';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_BINARY_STRING)) {
            $phpcsFile->fixer->replaceToken($stackPtr, substr($content, 1));
        }
    }

    // endregion

    // region Braces and return

    /**
     * Report a block on its own, not a control structure's, function's or class's, and fix it by removing its braces.
     *
     * A block with a function or class declaration in it is left alone, as the braces make the declaration
     * conditional.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the opening brace.
     */
    private function checkBraces(File $phpcsFile, int $stackPtr): void
    {
        // PHP_CodeSniffer makes the braces after `case 1:` the case's scope, but they're still a block on its own.
        $tokens = $phpcsFile->getTokens();
        $condition = $tokens[$stackPtr]['scope_condition'] ?? null;
        $isCaseBlock = is_int($condition) && in_array($tokens[$condition]['code'], [T_CASE, T_DEFAULT], true);
        $closer = $tokens[$stackPtr]['bracket_closer'] ?? ($isCaseBlock ? $tokens[$stackPtr]['scope_closer'] : null);
        $closer = is_int($closer) && $tokens[$closer]['code'] === T_CLOSE_CURLY_BRACKET ? $closer : null;
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        if (
            ($condition !== null && !$isCaseBlock) || !is_int($closer)
            || ($previous !== false && !in_array($tokens[$previous]['code'], self::STATEMENT_STARTS, true))
            || $phpcsFile->findNext(self::DECLARATIONS, ($stackPtr + 1), $closer) !== false
        ) {
            return;
        }

        $error = 'A block on its own needs no braces, as they don\'t make a scope';
        if (!$phpcsFile->addFixableError($error, $stackPtr, self::CODE_UNNEEDED_BRACES)) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $this->removeToken($phpcsFile, $stackPtr);
        $this->removeToken($phpcsFile, $closer);
        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Report a `return;` that's the last statement of a function or closure, and fix it by removing it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `return` keyword.
     */
    private function checkReturn(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $semicolon = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        $closer = $semicolon === false
            ? false
            : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($semicolon + 1), null, true);
        $function = $closer === false ? null : ($tokens[$closer]['scope_condition'] ?? null);
        $conditions = array_keys($tokens[$stackPtr]['conditions']);
        if (
            $semicolon === false || $tokens[$semicolon]['code'] !== T_SEMICOLON || !is_int($function)
            || !in_array($tokens[$function]['code'], [T_FUNCTION, T_CLOSURE], true)
            || $tokens[$function]['scope_closer'] !== $closer || end($conditions) !== $function
        ) {
            return;
        }

        $error = 'A return with no value as the last statement of a function does nothing, so it must be removed';
        if ($phpcsFile->findNext(T_WHITESPACE, ($stackPtr + 1), $semicolon, true) !== false) {
            $phpcsFile->addError($error, $stackPtr, self::CODE_USELESS_RETURN);
            return;
        }

        if (!$phpcsFile->addFixableError($error, $stackPtr, self::CODE_USELESS_RETURN)) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $next = $phpcsFile->findNext(T_WHITESPACE, ($semicolon + 1), null, true);
        if ($next !== false && $tokens[$next]['line'] === $tokens[$semicolon]['line']) {
            // A comment after it on its line takes its place.
            for ($i = $stackPtr; $i < $next; $i++) {
                $phpcsFile->fixer->replaceToken($i, '');
            }
        } else {
            // The statement goes with its line, and any blank lines before it.
            $previous = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($stackPtr - 1), null, true);
            for ($i = ($previous + 1); $i <= $semicolon; $i++) {
                $phpcsFile->fixer->replaceToken($i, '');
            }
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Remove a token in a fix, with its line if it's alone on it, or else with the whitespace beside it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the token.
     */
    private function removeToken(File $phpcsFile, int $ptr): void
    {
        $tokens = $phpcsFile->getTokens();
        $before = $ptr - 1;
        $after = $ptr + 1;
        $hasIndent = $tokens[$before]['code'] === T_WHITESPACE && $tokens[$before]['line'] === $tokens[$ptr]['line']
            && $tokens[$before]['column'] === 1;
        $isLineStart = $tokens[$ptr]['column'] === 1 || $hasIndent;
        $isLineEnd = isset($tokens[$after]) && $tokens[$after]['code'] === T_WHITESPACE
            && str_starts_with($tokens[$after]['content'], $phpcsFile->eolChar);

        $phpcsFile->fixer->replaceToken($ptr, '');
        if ($isLineStart && $isLineEnd) {
            $phpcsFile->fixer->replaceToken($after, substr($tokens[$after]['content'], strlen($phpcsFile->eolChar)));
            if ($hasIndent) {
                $phpcsFile->fixer->replaceToken($before, '');
            }
        } elseif ($isLineEnd && $tokens[$before]['code'] === T_WHITESPACE) {
            $phpcsFile->fixer->replaceToken($before, '');
        } elseif (!$isLineEnd && isset($tokens[$after]) && $tokens[$after]['code'] === T_WHITESPACE) {
            $phpcsFile->fixer->replaceToken($after, '');
        }
    }

    // endregion
}
