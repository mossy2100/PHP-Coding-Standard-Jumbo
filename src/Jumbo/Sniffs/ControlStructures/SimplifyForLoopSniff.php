<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\ControlStructures;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires a `while` loop where a `for` loop has only a condition, or none.
 *
 * PER Coding Style 3.1 has no rule for this, so this is an extra rule. Both are fixed:
 * - CanSimplify: `for (; $i < 10;)` as `while ($i < 10)`. From Generic.CodeAnalysis.ForLoopShouldBeWhileLoop, which
 *   this replaces in Jumbo, as it has no fix and doesn't report the next case.
 * - Infinite: `for (;;)` as `while (true)`, from php-cs-fixer's `empty_loop_condition`.
 *
 * A condition of several expressions, as in `for (; $a, $b;)`, can't be a `while` loop's, so it's left alone. A fix
 * never deletes a comment: one in a part the fix would remove is reported, not fixed.
 */
class SimplifyForLoopSniff implements Sniff
{
    public const string CODE_CAN_SIMPLIFY = 'CanSimplify';

    public const string CODE_INFINITE = 'Infinite';

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_FOR];
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
}
