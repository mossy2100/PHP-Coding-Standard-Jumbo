<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PHP;

use PERCS31\Helpers\FixerHelper;
use PERCS31\Helpers\TokenHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires code that does nothing to be removed.
 *
 * PER Coding Style 3.1 has no rules for these, so this is an extra rule. Each is fixed:
 * - NullsafeThis: `$this?->` as `$this->`, as `$this` is never null. From php-cs-fixer's
 *   `no_useless_nullsafe_operator`.
 * - BinaryString: a string without the `b` prefix, which does nothing. From Laravel Pint's `no_binary_string`.
 * - UnneededBraces: a block on its own, not a control structure's, without its braces, as they don't make a scope.
 *   From Laravel Pint's `no_unneeded_braces`. Braces that wrap a whole `case` body are
 *   PERCS31.ControlStructures.SwitchDeclaration's, as CaseBodyInBraces.
 * - UselessReturn: no `return;` as a function's last statement. From Laravel Pint's `no_useless_return`.
 *
 * A fix never deletes a comment: where one is in the code a fix would remove, it's reported, not fixed.
 */
class RemoveUselessCodeSniff implements Sniff
{
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
        match ($phpcsFile->getTokens()[$stackPtr]['code']) {
            T_NULLSAFE_OBJECT_OPERATOR => self::checkNullsafe($phpcsFile, $stackPtr),
            T_OPEN_CURLY_BRACKET => self::checkBraces($phpcsFile, $stackPtr),
            T_RETURN => self::checkReturn($phpcsFile, $stackPtr),
            default => self::checkBinaryString($phpcsFile, $stackPtr),
        };
    }

    /**
     * Report `$this?->`, and fix it to `$this->`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `?->`.
     */
    private static function checkNullsafe(File $phpcsFile, int $stackPtr): void
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
    private static function checkBinaryString(File $phpcsFile, int $stackPtr): void
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

    /**
     * Report a block on its own, not a control structure's, function's or class's, and fix it by removing its braces.
     *
     * A block with a function or class declaration in it is left alone, as the braces make the declaration
     * conditional.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the opening brace.
     */
    private static function checkBraces(File $phpcsFile, int $stackPtr): void
    {
        // PHP_CodeSniffer makes the braces after `case 1:` the case's scope. Braces that wrap the whole case body are
        // PERCS31.ControlStructures.SwitchDeclaration's, as CaseBodyInBraces. Others, followed by more of the body,
        // are a block on its own.
        $tokens = $phpcsFile->getTokens();
        $condition = $tokens[$stackPtr]['scope_condition'] ?? null;
        $isCaseBlock = is_int($condition) && in_array($tokens[$condition]['code'], [T_CASE, T_DEFAULT], true)
            && !TokenHelper::wrapsCaseBody($phpcsFile, $stackPtr);
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
        FixerHelper::removeWithLine($phpcsFile, $stackPtr);
        FixerHelper::removeWithLine($phpcsFile, $closer);
        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Report a `return;` that's the last statement of a function or closure, and fix it by removing it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `return` keyword.
     */
    private static function checkReturn(File $phpcsFile, int $stackPtr): void
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
}
