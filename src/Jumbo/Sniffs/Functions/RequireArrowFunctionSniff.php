<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use SlevomatCodingStandard\Helpers\ScopeHelper;
use SlevomatCodingStandard\Sniffs\Functions\RequireArrowFunctionSniff as SlevomatSniff;

/**
 * Requires an arrow function for a closure whose body is only a `return`, as the parent does, but only where that
 * doesn't change what the code does. The parent converts any such closure, which can change its meaning or break it:
 * - An operator after the closure joins the arrow function's body, so `function () { return 1; } ?? null` becomes
 *   `fn() => 1 ?? null`, which returns `1 ?? null`.
 * - An arrow function captures every outer variable it uses, so `function () { return $x; }`, which doesn't see an
 *   outer `$x`, would start returning it.
 * - A bare `return;` becomes `fn() =>`, which PHP rejects.
 *
 * So a closure is only converted when its `return` has an expression, nothing after the closure continues the
 * expression it's in, and every variable in the expression is a parameter, a `use` variable, `$this` or a superglobal.
 * One with a comment in a part the fix would remove is reported, but not fixed, so the comment isn't lost.
 */
class RequireArrowFunctionSniff extends SlevomatSniff
{
    /**
     * The tokens that can follow a closure without joining an arrow function's body.
     */
    private const array SAFE_AFTER = [
        T_SEMICOLON,
        T_COMMA,
        T_CLOSE_PARENTHESIS,
        T_CLOSE_SQUARE_BRACKET,
        T_CLOSE_SHORT_ARRAY,
        T_CLOSE_CURLY_BRACKET,
        T_INLINE_ELSE,
        T_CLOSE_TAG,
    ];

    /**
     * The variables every scope sees, so using one in an arrow function captures nothing.
     */
    private const array SUPERGLOBALS = [
        '$this',
        '$GLOBALS',
        '$_SERVER',
        '$_GET',
        '$_POST',
        '$_FILES',
        '$_COOKIE',
        '$_SESSION',
        '$_REQUEST',
        '$_ENV',
    ];

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $closurePointer The position of the `function` keyword.
     */
    public function process(File $phpcsFile, int $closurePointer): void
    {
        $tokens = $phpcsFile->getTokens();
        $opener = $tokens[$closurePointer]['scope_opener'] ?? null;
        $closer = $tokens[$closurePointer]['scope_closer'] ?? null;
        $parametersCloser = $tokens[$closurePointer]['parenthesis_closer'] ?? null;
        if (!is_int($opener) || !is_int($closer) || !is_int($parametersCloser)) {
            return;
        }

        // A bare `return;` has nothing to put after `=>`.
        $return = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($opener + 1), $closer, true);
        $first = $return === false ? false : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($return + 1), $closer, true);
        if ($return === false || $tokens[$return]['code'] !== T_RETURN || $first === false) {
            return;
        }

        $semicolon = self::findReturnEnd($phpcsFile, $return, $closer);
        $after = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($closer + 1), null, true);
        if (
            $tokens[$first]['code'] === T_SEMICOLON || $semicolon === null
            || ($after !== false && !in_array($tokens[$after]['code'], self::SAFE_AFTER, true))
            || !self::usesOnlyItsOwnVariables($phpcsFile, $closurePointer, $first, $semicolon)
        ) {
            return;
        }

        // The fix removes what's between the parameters and the expression, and after the `return`'s semicolon.
        $hasComment = $phpcsFile->findNext(Tokens::COMMENT_TOKENS, ($parametersCloser + 1), $first) !== false
            || $phpcsFile->findNext(Tokens::COMMENT_TOKENS, ($semicolon + 1), $closer) !== false;
        if ($hasComment) {
            if (!self::hasReferenceUse($phpcsFile, $parametersCloser, $opener)) {
                $phpcsFile->addError('Use arrow function.', $closurePointer, self::CODE_REQUIRED_ARROW_FUNCTION);
            }

            return;
        }

        parent::process($phpcsFile, $closurePointer);
    }

    /**
     * Find the semicolon that ends a closure's `return` statement.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $return The position of the `return`.
     * @param int $closer The position of the closure's closing brace.
     * @return ?int The position of the semicolon, or null if there's none.
     */
    private static function findReturnEnd(File $phpcsFile, int $return, int $closer): ?int
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = ($return + 1); $i < $closer; $i++) {
            if ($tokens[$i]['code'] === T_SEMICOLON && ScopeHelper::isInSameScope($phpcsFile, $return, $i)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Check whether every variable in a closure's `return` expression is one the closure has of its own: a parameter,
     * a `use` variable, `$this` or a superglobal. A closure nested in the expression has its own scope, so only its
     * `use` variables count. A variable variable, or a nested arrow function, which captures from the scope around it,
     * isn't checked, so it never counts as safe.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $closurePointer The position of the `function` keyword.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of the semicolon after it.
     * @return bool True if it uses no other variable.
     */
    private static function usesOnlyItsOwnVariables(File $phpcsFile, int $closurePointer, int $start, int $end): bool
    {
        $tokens = $phpcsFile->getTokens();
        $own = self::SUPERGLOBALS;
        $parametersOpener = (int) $tokens[$closurePointer]['parenthesis_opener'];
        $headEnd = (int) $tokens[$closurePointer]['scope_opener'];
        for ($i = $parametersOpener; $i < $headEnd; $i++) {
            if ($tokens[$i]['code'] === T_VARIABLE) {
                $own[] = $tokens[$i]['content'];
            }
        }

        for ($i = $start; $i < $end; $i++) {
            $code = $tokens[$i]['code'];
            if (in_array($code, [T_DOLLAR, T_FN], true)) {
                return false;
            }

            // A nested closure's parameters and body are its own; its `use` list is read from this scope.
            if ($code === T_CLOSURE) {
                $i = (int) $tokens[$i]['parenthesis_closer'];
                continue;
            }

            $condition = $tokens[$i]['scope_condition'] ?? null;
            if ($code === T_OPEN_CURLY_BRACKET && is_int($condition) && $tokens[$condition]['code'] === T_CLOSURE) {
                $i = (int) $tokens[$i]['scope_closer'];
                continue;
            }

            if ($code === T_VARIABLE && !in_array($tokens[$i]['content'], $own, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether a closure has a `use` list with a variable by reference, which the parent leaves alone.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $parametersCloser The position of the closing parenthesis of its parameters.
     * @param int $opener The position of its opening brace.
     * @return bool True if it has.
     */
    private static function hasReferenceUse(File $phpcsFile, int $parametersCloser, int $opener): bool
    {
        $tokens = $phpcsFile->getTokens();
        $use = $phpcsFile->findNext(T_USE, ($parametersCloser + 1), $opener);
        $useCloser = $use === false ? null : ($tokens[$use]['parenthesis_closer'] ?? null);

        return is_int($useCloser) && $phpcsFile->findNext(T_BITWISE_AND, (int) $use, $useCloser) !== false;
    }
}
