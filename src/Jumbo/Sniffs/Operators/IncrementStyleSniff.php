<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\Operators;

use Jumbo\Helpers\FunctionCallHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires a standalone increment or decrement to be written after its variable: `$i++;`, not `++$i;`.
 *
 * PER Coding Style 3.1 has no rule for this, so this is an extra rule, from Laravel Pint's `increment_style` (post).
 * A standalone one is a statement of its own, or a `for` loop's step, where its value isn't used, so the two forms do
 * the same. One whose value is used, as in `$x = ++$i`, is left alone. Reported as PreIncrement, and fixed.
 */
class IncrementStyleSniff implements Sniff
{
    public const string CODE_PRE_INCREMENT = 'PreIncrement';

    /**
     * The tokens a variable can be made of, after its first token.
     *
     * @var list<int|string>
     */
    private const array VARIABLE_TOKENS = [
        T_VARIABLE,
        T_STRING,
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_OPEN_SQUARE_BRACKET,
        T_OPEN_CURLY_BRACKET,
    ];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_INC, T_DEC];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $start = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        if (
            $start === false
            || !in_array(
                $tokens[$start]['code'],
                [T_VARIABLE, T_STRING, T_SELF, T_STATIC, T_PARENT],
                true,
            )
        ) {
            return;
        }

        $end = self::findVariableEnd($phpcsFile, $start);
        if (
            !FunctionCallHelper::isStatement($phpcsFile, $stackPtr, $end)
            && !self::isForStep($phpcsFile, $stackPtr, $end)
        ) {
            return;
        }

        $operator = $tokens[$stackPtr]['content'];
        $variable = $phpcsFile->getTokensAsString($start, ($end - $start + 1));
        $error = 'Write a standalone increment or decrement after its variable; expected %s but found %s';
        $data = ["{$variable}{$operator}", "{$operator}{$variable}"];
        if (!$phpcsFile->addFixableError($error, $stackPtr, self::CODE_PRE_INCREMENT, $data)) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        for ($i = $stackPtr; $i < $start; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->addContent($end, $operator);
        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Find the last token of the variable an increment applies to, such as the `]` of `$a['b']`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the variable's first token.
     * @return int The position of its last token.
     */
    private static function findVariableEnd(File $phpcsFile, int $start): int
    {
        $tokens = $phpcsFile->getTokens();
        $end = $start;
        for ($i = ($start + 1); isset($tokens[$i]); $i++) {
            if (!in_array($tokens[$i]['code'], self::VARIABLE_TOKENS, true)) {
                break;
            }

            $end = $tokens[$i]['bracket_closer'] ?? $i;
            $i = $end;
        }

        return $end;
    }

    /**
     * Check whether an increment is a whole expression in a `for` loop's step, where its value isn't used.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of its first token.
     * @param int $end The position of its last token.
     * @return bool True if it is.
     */
    private static function isForStep(File $phpcsFile, int $start, int $end): bool
    {
        $tokens = $phpcsFile->getTokens();
        $openers = array_keys($tokens[$start]['nested_parenthesis'] ?? []);
        $opener = end($openers);
        $owner = is_int($opener) ? ($tokens[$opener]['parenthesis_owner'] ?? null) : null;
        if (!is_int($opener) || !is_int($owner) || $tokens[$owner]['code'] !== T_FOR) {
            return false;
        }

        $semicolons = 0;
        for ($i = ($opener + 1); $i < $start; $i++) {
            $semicolons += $tokens[$i]['code'] === T_SEMICOLON ? 1 : 0;
        }

        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($start - 1), null, true);
        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($end + 1), null, true);

        return $semicolons === 2 && $previous !== false && $next !== false
            && in_array($tokens[$previous]['code'], [T_SEMICOLON, T_COMMA], true)
            && in_array($tokens[$next]['code'], [T_COMMA, T_CLOSE_PARENTHESIS], true);
    }
}
