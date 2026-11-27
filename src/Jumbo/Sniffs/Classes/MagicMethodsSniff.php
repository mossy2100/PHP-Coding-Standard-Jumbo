<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Utils\ObjectDeclarations;

/**
 * Checks what a class's magic methods imply: `Stringable` said outright, and no `__sleep()` or `__wakeup()`.
 *
 * PER Coding Style 3.1 has no rules for these, so this is an extra rule:
 * - MissingStringable: a class with `__toString()` that neither extends a class nor implements an interface gets
 *   `implements \Stringable`, which PHP adds anyway. From php-cs-fixer's `stringable_for_to_string`. Fixed.
 * - SleepWakeup, a warning: `__sleep()` and `__wakeup()` are superseded by `__serialize()` and `__unserialize()`. From
 *   php-cs-fixer's `modern_serialization_methods`, which renames them, but `__sleep()` returns property names where
 *   `__serialize()` returns data, so a renamed method would be wrong. Not fixed.
 */
class MagicMethodsSniff implements Sniff
{
    public const string CODE_MISSING_STRINGABLE = 'MissingStringable';

    public const string CODE_SLEEP_WAKEUP = 'SleepWakeup';

    /**
     * The serialization methods that are superseded, by lower-case name, with what replaces each.
     *
     * @var array<string, string>
     */
    private const array SUPERSEDED = ['__sleep' => '__serialize', '__wakeup' => '__unserialize'];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_CLASS, T_ANON_CLASS, T_ENUM, T_TRAIT];
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
        if (!isset($tokens[$stackPtr]['scope_opener'], $tokens[$stackPtr]['scope_closer'])) {
            return;
        }

        $methods = ObjectDeclarations::getDeclaredMethods($phpcsFile, $stackPtr);
        self::checkSerialization($phpcsFile, $methods);
        if (
            $tokens[$stackPtr]['code'] === T_CLASS
            && ObjectDeclarations::findExtendedClassName($phpcsFile, $stackPtr) === false
        ) {
            self::checkStringable($phpcsFile, $stackPtr, $methods);
        }
    }

    /**
     * Report a class with `__toString()` that doesn't say it implements `Stringable`, and fix it.
     *
     * Only a class that implements no interface is reported, as another interface may extend `Stringable`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the `class` keyword.
     * @param array<string, int> $methods The class's methods, by name.
     */
    private static function checkStringable(File $phpcsFile, int $classPtr, array $methods): void
    {
        $hasToString
            = array_any(array_keys($methods), static fn(string $name): bool => strtolower($name) === '__tostring');
        $namePtr = $phpcsFile->findNext(T_STRING, ($classPtr + 1), $phpcsFile->getTokens()[$classPtr]['scope_opener']);
        if (
            !$hasToString || $namePtr === false
            || ObjectDeclarations::findImplementedInterfaceNames($phpcsFile, $classPtr) !== false
        ) {
            return;
        }

        $error = 'A class with __toString() must say it implements Stringable';
        if ($phpcsFile->addFixableError($error, $classPtr, self::CODE_MISSING_STRINGABLE)) {
            $phpcsFile->fixer->addContent($namePtr, ' implements \\Stringable');
        }
    }

    /**
     * Warn about `__sleep()` and `__wakeup()` methods.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array<string, int> $methods The class's methods, by name.
     */
    private static function checkSerialization(File $phpcsFile, array $methods): void
    {
        foreach ($methods as $name => $functionPtr) {
            $replacement = self::SUPERSEDED[strtolower($name)] ?? null;
            if ($replacement !== null) {
                $warning = '%s() is superseded by %s(), which works with the data itself, not property names; '
                    . 'implement that instead';
                $phpcsFile->addWarning($warning, $functionPtr, self::CODE_SLEEP_WAKEUP, [$name, $replacement]);
            }
        }
    }
}
