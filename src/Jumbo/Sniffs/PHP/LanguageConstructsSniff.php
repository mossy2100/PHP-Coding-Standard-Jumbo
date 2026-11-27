<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PHP;

use Jumbo\Helpers\FunctionCallHelper;
use PERCS31\Helpers\ExpressionHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Reports language constructs that have a better form, and fixes them.
 *
 * PER Coding Style 3.1 has no rule for these, so this is an extra rule. Each check has its own code:
 * - DieAlias: `die` to `exit`, its main name.
 * - PrintStatement: `print` to `echo`, as a statement, where its value isn't used.
 * - ConsecutiveIssets: `isset($a) && isset($b)` to `isset($a, $b)`, only for calls that are whole operands of `&&`, so
 *   `!isset($a) && isset($b)` and `isset($a) && isset($b) === $c` are left alone. From php-cs-fixer's
 *   `combine_consecutive_issets`.
 * - ConsecutiveUnsets: `unset($a); unset($b);` to `unset($a, $b);`, for statements with nothing between them, not even
 *   a comment. From php-cs-fixer's `combine_consecutive_unsets`.
 *
 * A fix never deletes a comment: where one is in the code a fix would remove, it's reported, not fixed.
 */
class LanguageConstructsSniff implements Sniff
{
    public const string CODE_DIE_ALIAS = 'DieAlias';

    public const string CODE_PRINT_STATEMENT = 'PrintStatement';

    public const string CODE_CONSECUTIVE_ISSETS = 'ConsecutiveIssets';

    public const string CODE_CONSECUTIVE_UNSETS = 'ConsecutiveUnsets';

    /**
     * The closing brackets, which end an expression.
     *
     * @var list<int|string>
     */
    private const array CLOSERS
        = [T_CLOSE_PARENTHESIS, T_CLOSE_SQUARE_BRACKET, T_CLOSE_SHORT_ARRAY, T_CLOSE_CURLY_BRACKET];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_EXIT, T_PRINT, T_ISSET, T_UNSET];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        match ($phpcsFile->getTokens()[$stackPtr]['code']) {
            T_EXIT => self::processDie($phpcsFile, $stackPtr),
            T_PRINT => self::processPrint($phpcsFile, $stackPtr),
            T_ISSET => self::processIssets($phpcsFile, $stackPtr),
            default => self::processUnsets($phpcsFile, $stackPtr),
        };
    }

    /**
     * Report `die`, and fix it to `exit`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the keyword.
     */
    private static function processDie(File $phpcsFile, int $stackPtr): void
    {
        $content = $phpcsFile->getTokens()[$stackPtr]['content'];
        if (strtolower($content) !== 'die') {
            return;
        }

        if ($phpcsFile->addFixableError('Use exit, not its alias %s', $stackPtr, self::CODE_DIE_ALIAS, [$content])) {
            $phpcsFile->fixer->replaceToken($stackPtr, 'exit');
        }
    }

    /**
     * Report `print` as a statement, whose value isn't used, and fix it to `echo`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the keyword.
     */
    private static function processPrint(File $phpcsFile, int $stackPtr): void
    {
        $end = $phpcsFile->findNext([T_SEMICOLON, T_CLOSE_TAG], ($stackPtr + 1));
        $last = $end === false ? false : $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($end - 1), null, true);
        if ($last === false || !FunctionCallHelper::isStatement($phpcsFile, $stackPtr, $last)) {
            return;
        }

        if ($phpcsFile->addFixableError('Use echo, not print, as a statement', $stackPtr, self::CODE_PRINT_STATEMENT)) {
            $phpcsFile->fixer->replaceToken($stackPtr, 'echo');
        }
    }

    /**
     * Report `isset()` calls joined by `&&`, from the first of them, and fix them to one call.
     *
     * Only calls that are whole operands of `&&` are joined, so `!isset($a) && isset($b)` and `isset($a) && isset($b)
     * === $c` are left alone.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `isset` keyword.
     */
    private static function processIssets(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        if ($previous !== false && !self::isLooseNeighbour($phpcsFile, $previous)) {
            return;
        }

        // A call after `&&` that joins with the one before it is reported with that one.
        $before
            = $previous === false ? false : $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($previous - 1), null, true);
        if ($before !== false && $tokens[$previous]['code'] === T_BOOLEAN_AND) {
            $earlier = self::findCallBefore($phpcsFile, $before, T_ISSET);
            $beforeEarlier = $earlier === null
                ? false
                : $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($earlier - 1), null, true);
            $isEarlierJoined = $beforeEarlier === false || self::isLooseNeighbour($phpcsFile, $beforeEarlier);
            if ($earlier !== null && $isEarlierJoined) {
                return;
            }
        }

        $chain = [$stackPtr];
        $next = self::findNextIsset($phpcsFile, $stackPtr);
        while ($next !== null) {
            $chain[] = $next;
            $next = self::findNextIsset($phpcsFile, $next);
        }

        $lastCloser = FunctionCallHelper::closer($phpcsFile, end($chain));
        $after = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($lastCloser + 1), null, true);
        if ($after === false || !self::isLooseNeighbour($phpcsFile, $after)) {
            array_pop($chain);
        }

        self::reportChain(
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
    private static function processUnsets(File $phpcsFile, int $stackPtr): void
    {
        if (!self::isUnsetStatement($phpcsFile, $stackPtr)) {
            return;
        }

        // A statement straight after another is reported with that one.
        $tokens = $phpcsFile->getTokens();
        $previous = $phpcsFile->findPrevious(T_WHITESPACE, ($stackPtr - 1), null, true);
        $before = $previous === false ? false : $phpcsFile->findPrevious(T_WHITESPACE, ($previous - 1), null, true);
        if ($before !== false && $tokens[$previous]['code'] === T_SEMICOLON) {
            $earlier = self::findCallBefore($phpcsFile, $before, T_UNSET);
            if ($earlier !== null && self::isUnsetStatement($phpcsFile, $earlier)) {
                return;
            }
        }

        $chain = [$stackPtr];
        while (true) {
            $semicolon = $phpcsFile->findNext(
                T_WHITESPACE,
                (FunctionCallHelper::closer($phpcsFile, end($chain)) + 1),
                null,
                true,
            );
            $next = $semicolon === false ? false : $phpcsFile->findNext(T_WHITESPACE, ($semicolon + 1), null, true);
            if ($next === false || $tokens[$next]['code'] !== T_UNSET || !self::isUnsetStatement($phpcsFile, $next)) {
                break;
            }

            $chain[] = $next;
        }

        self::reportChain(
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
    private static function reportChain(File $phpcsFile, array $chain, string $error, string $code): void
    {
        if (count($chain) < 2) {
            return;
        }

        // The code between each call's closing parenthesis and the next call's opening one, which the fix replaces.
        $tokens = $phpcsFile->getTokens();
        $joins = [];
        $hasComment = false;
        foreach (array_slice($chain, 1) as $index => $keywordPtr) {
            $join = [
                FunctionCallHelper::closer($phpcsFile, $chain[$index]),
                FunctionCallHelper::opener($phpcsFile, $keywordPtr),
            ];
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
    private static function findNextIsset(File $phpcsFile, int $issetPtr): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $operator = $phpcsFile->findNext(
            Tokens::EMPTY_TOKENS,
            (FunctionCallHelper::closer($phpcsFile, $issetPtr) + 1),
            null,
            true,
        );
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
    private static function findCallBefore(File $phpcsFile, int $closer, int|string $keyword): ?int
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
    private static function isUnsetStatement(File $phpcsFile, int $unsetPtr): bool
    {
        $closer = FunctionCallHelper::closer($phpcsFile, $unsetPtr);
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
    private static function isLooseNeighbour(File $phpcsFile, int $ptr): bool
    {
        $code = $phpcsFile->getTokens()[$ptr]['code'];
        $precedences = ExpressionHelper::precedences();
        if (isset($precedences[$code])) {
            return $precedences[$code] <= $precedences[T_BOOLEAN_AND];
        }

        return in_array($code, self::CLOSERS, true) || ExpressionHelper::isBoundary($phpcsFile, $ptr);
    }
}
