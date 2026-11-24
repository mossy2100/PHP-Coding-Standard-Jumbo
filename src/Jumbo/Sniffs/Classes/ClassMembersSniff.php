<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\Classes;

use Jumbo\Helpers\FunctionCallHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\FunctionDeclarations;
use PHPCSUtils\Utils\ObjectDeclarations;
use SlevomatCodingStandard\Helpers\UseStatementHelper;

/**
 * Checks a class's members against what its header allows: no `protected` member in a final class, a private method
 * that doesn't use `$this` made static, `Stringable` said outright, and no `__sleep()` or `__wakeup()`.
 *
 * PER Coding Style 3.1 has no rules for these, so this is an extra rule. The first three are fixed:
 * - ProtectedToPrivate: a `protected` property, method or constant in a final class that neither extends a class nor
 *   uses a trait, so nothing else can see it, is made `private`. From php-cs-fixer's `protected_to_private`.
 * - StaticPrivateMethod: a private method whose body never uses `$this` is made static, and its `$this->name()` calls
 *   in the class become `self::name()`. From php-cs-fixer's `static_private_method`. The method is left alone if it
 *   calls a method of the class that isn't static, or one the sniff can't see, uses `parent::`, a closure that isn't
 *   static, or `get_class()` with no argument. A method that calls another that's made static qualifies on the next
 *   phpcbf pass.
 * - MissingStringable: a class with `__toString()` that neither extends a class nor implements an interface gets
 *   `implements \Stringable`, which PHP adds anyway. From php-cs-fixer's `stringable_for_to_string`.
 * - SleepWakeup, a warning: `__sleep()` and `__wakeup()` are superseded by `__serialize()` and `__unserialize()`. From
 *   php-cs-fixer's `modern_serialization_methods`, which renames them, but `__sleep()` returns property names where
 *   `__serialize()` returns data, so a renamed method would be wrong. Not fixed.
 */
class ClassMembersSniff implements Sniff
{
    public const string CODE_PROTECTED_TO_PRIVATE = 'ProtectedToPrivate';

    public const string CODE_STATIC_PRIVATE_METHOD = 'StaticPrivateMethod';

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
        $code = $tokens[$stackPtr]['code'];
        if ($code === T_TRAIT) {
            return;
        }

        self::checkStaticMethods($phpcsFile, $stackPtr, $methods);
        if ($code !== T_CLASS) {
            return;
        }

        $extends = ObjectDeclarations::findExtendedClassName($phpcsFile, $stackPtr);
        if ($extends !== false) {
            return;
        }

        self::checkProtected($phpcsFile, $stackPtr);
        self::checkStringable($phpcsFile, $stackPtr, $methods);
    }

    // region Visibility and Stringable

    /**
     * Report each `protected` member of a final class that uses no trait, and fix it to `private`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the `class` keyword.
     */
    private static function checkProtected(File $phpcsFile, int $classPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if (!ObjectDeclarations::getClassProperties($phpcsFile, $classPtr)['is_final']) {
            return;
        }

        $members = [];
        for ($i = $tokens[$classPtr]['scope_opener']; $i < $tokens[$classPtr]['scope_closer']; $i++) {
            if (self::findClass($tokens[$i]) !== $classPtr) {
                continue;
            }

            if ($tokens[$i]['code'] === T_USE && UseStatementHelper::isTraitUse($phpcsFile, $i)) {
                return;
            }

            if (in_array($tokens[$i]['code'], [T_PROTECTED, T_PROTECTED_SET], true)) {
                $members[] = $i;
            }
        }

        foreach ($members as $ptr) {
            $isSet = $tokens[$ptr]['code'] === T_PROTECTED_SET;
            $error = 'A member of a final class that extends no class and uses no trait must be private, not protected';
            if ($phpcsFile->addFixableError($error, $ptr, self::CODE_PROTECTED_TO_PRIVATE)) {
                $phpcsFile->fixer->replaceToken($ptr, $isSet ? 'private(set)' : 'private');
            }
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
        $hasToString = array_any(
            array_keys($methods),
            static fn(string $name): bool => strtolower($name) === '__tostring',
        );
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

    // endregion

    // region Static private methods

    /**
     * Report each private method that doesn't use `$this` and isn't static, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the class's keyword.
     * @param array<string, int> $methods The class's methods, by name.
     */
    private static function checkStaticMethods(File $phpcsFile, int $classPtr, array $methods): void
    {
        $statics = [];
        foreach ($methods as $name => $functionPtr) {
            $statics[strtolower($name)] = FunctionDeclarations::getProperties(
                $phpcsFile,
                $functionPtr,
            )['is_static'] === true;
        }

        foreach ($methods as $name => $functionPtr) {
            $properties = FunctionDeclarations::getProperties($phpcsFile, $functionPtr);
            if (
                $properties['scope'] !== 'private' || $properties['is_static'] || !$properties['has_body']
                || str_starts_with($name, '__') || !self::canBeStatic($phpcsFile, $functionPtr, $statics)
            ) {
                continue;
            }

            $error = 'Private method %s() doesn\'t use $this, so it must be static';
            if ($phpcsFile->addFixableError($error, $functionPtr, self::CODE_STATIC_PRIVATE_METHOD, [$name])) {
                self::fixStaticMethod($phpcsFile, $classPtr, $functionPtr, strtolower($name));
            }
        }
    }

    /**
     * Check whether a method's body can be static: it doesn't use `$this`, `parent::`, a closure that isn't static, or
     * `get_class()` with no argument, and calls only static methods of the class with `self::` or `static::`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $functionPtr The position of the method's `function` keyword.
     * @param array<string, bool> $statics Whether each of the class's methods is static, by lower-case name.
     * @return bool True if it can.
     */
    private static function canBeStatic(File $phpcsFile, int $functionPtr, array $statics): bool
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $tokens[$functionPtr]['scope_opener']; $i < $tokens[$functionPtr]['scope_closer']; $i++) {
            $code = $tokens[$i]['code'];
            $content = $tokens[$i]['content'];
            $isString = in_array($code, [T_DOUBLE_QUOTED_STRING, T_HEREDOC], true);
            $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($i - 1), null, true);
            $isBoundClosure = in_array($code, [T_CLOSURE, T_FN], true)
                && ($previous === false || $tokens[$previous]['code'] !== T_STATIC);
            if (
                $code === T_PARENT || $isBoundClosure || ($code === T_VARIABLE && $content === '$this')
                || ($isString && str_contains($content, '$this'))
                || (FunctionCallHelper::globalFunctionName($phpcsFile, $i) === 'get_class'
                    && FunctionCallHelper::arguments($phpcsFile, $i) === [])
            ) {
                return false;
            }

            if (in_array($code, [T_SELF, T_STATIC], true) && !self::callsStaticMethod($phpcsFile, $i, $statics)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check that a `self::` or `static::` is no call, or a call to a static method declared in the class.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $ptr The position of the `self` or `static` keyword.
     * @param array<string, bool> $statics Whether each of the class's methods is static, by lower-case name.
     * @return bool True if it is.
     */
    private static function callsStaticMethod(File $phpcsFile, int $ptr, array $statics): bool
    {
        $tokens = $phpcsFile->getTokens();
        $operator = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($ptr + 1), null, true);
        $name = $operator === false ? false : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($operator + 1), null, true);
        $opener = $name === false ? false : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($name + 1), null, true);
        if (
            $opener === false || $tokens[$operator]['code'] !== T_DOUBLE_COLON || $tokens[$name]['code'] !== T_STRING
            || $tokens[$opener]['code'] !== T_OPEN_PARENTHESIS
        ) {
            return true;
        }

        return $statics[strtolower($tokens[$name]['content'])] ?? false;
    }

    /**
     * Make a method static, and change its `$this->name()` calls in the class to `self::name()`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the class's keyword.
     * @param int $functionPtr The position of the method's `function` keyword.
     * @param string $name The method's lower-case name.
     */
    private static function fixStaticMethod(File $phpcsFile, int $classPtr, int $functionPtr, string $name): void
    {
        $tokens = $phpcsFile->getTokens();
        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->addContentBefore($functionPtr, 'static ');
        for ($i = $tokens[$classPtr]['scope_opener']; $i < $tokens[$classPtr]['scope_closer']; $i++) {
            if ($tokens[$i]['code'] !== T_VARIABLE || $tokens[$i]['content'] !== '$this') {
                continue;
            }

            $operator = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($i + 1), null, true);
            $method = $operator === false
                ? false
                : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($operator + 1), null, true);
            $opener = $method === false ? false : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($method + 1), null, true);
            if (
                $opener !== false && self::findClass($tokens[$i]) === $classPtr
                && $tokens[$operator]['code'] === T_OBJECT_OPERATOR && strtolower($tokens[$method]['content']) === $name
                && $tokens[$opener]['code'] === T_OPEN_PARENTHESIS
            ) {
                $phpcsFile->fixer->replaceToken($i, 'self');
                $phpcsFile->fixer->replaceToken($operator, '::');
            }
        }

        $phpcsFile->fixer->endChangeset();
    }

    // endregion

    /**
     * Find the class, interface, trait or enum a token is directly in, not counting one nested in it.
     *
     * @param array<string, mixed> $token The token.
     * @return ?int The position of its keyword, or null if it isn't in one.
     */
    private static function findClass(array $token): ?int
    {
        $conditions = $token['conditions'] ?? [];
        $classPtr = null;
        foreach (is_array($conditions) ? $conditions : [] as $ptr => $code) {
            if ((is_int($code) || is_string($code)) && isset(Tokens::OO_SCOPE_TOKENS[$code])) {
                $classPtr = $ptr;
            }
        }

        return is_int($classPtr) ? $classPtr : null;
    }
}
