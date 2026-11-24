<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\Scopes;
use ReflectionClass;
use SlevomatCodingStandard\Helpers\NamespaceHelper;
use SlevomatCodingStandard\Helpers\ReferencedNameHelper;
use SlevomatCodingStandard\Helpers\UseStatementHelper;

/**
 * Requires names PHP defines to be written in their own case: built-in classes, as `new \DateTime()`, not
 * `new \datetime()`, and magic methods, as `__toString()`, not `__tostring()`.
 *
 * PER Coding Style 3.1 has no rule for this, so this is an extra rule. PHP doesn't care about the case of a class or
 * method name, but a reader and a search do. Both are fixed:
 * - ClassNameCase: a reference to a built-in class, interface or enum, or a `use` statement that imports one, from
 *   php-cs-fixer's `class_reference_name_casing`. Each name is resolved as PHP does, and matched against the classes
 *   built into the PHP running PHP_CodeSniffer, so a project class is never touched. A name only differs from the real
 *   one in case, so an alias, as in `use DateTime as Moment`, is left alone.
 * - MagicMethodCase: a magic method's name, where it's declared and where it's called with `->`, `?->` or `::`, from
 *   Laravel Pint's `magic_method_casing`.
 */
class BuiltInNameCaseSniff implements Sniff
{
    public const string CODE_CLASS_NAME_CASE = 'ClassNameCase';

    public const string CODE_MAGIC_METHOD_CASE = 'MagicMethodCase';

    /**
     * The magic methods, by lower-case name.
     *
     * @var array<string, string>
     */
    private const array MAGIC_METHODS = [
        '__construct' => '__construct',
        '__destruct' => '__destruct',
        '__call' => '__call',
        '__callstatic' => '__callStatic',
        '__get' => '__get',
        '__set' => '__set',
        '__isset' => '__isset',
        '__unset' => '__unset',
        '__sleep' => '__sleep',
        '__wakeup' => '__wakeup',
        '__serialize' => '__serialize',
        '__unserialize' => '__unserialize',
        '__tostring' => '__toString',
        '__invoke' => '__invoke',
        '__set_state' => '__set_state',
        '__clone' => '__clone',
        '__debuginfo' => '__debugInfo',
    ];

    /**
     * The tokens a class name is.
     *
     * @var list<int|string>
     */
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * The built-in classes, interfaces and enums, by lower-case name, once found.
     *
     * @var array<string, string>|null
     */
    private static ?array $builtInClasses = null;

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_OPEN_TAG, T_USE, T_FUNCTION, T_STRING];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $code = $phpcsFile->getTokens()[$stackPtr]['code'];
        if ($code === T_OPEN_TAG) {
            self::checkReferences($phpcsFile, $stackPtr);
        } elseif ($code === T_USE) {
            self::checkUseStatement($phpcsFile, $stackPtr);
        } elseif ($code === T_FUNCTION) {
            self::checkMethodDeclaration($phpcsFile, $stackPtr);
        } else {
            self::checkMethodCall($phpcsFile, $stackPtr);
        }
    }

    // region Classes

    /**
     * Check every class reference in the file, from its first opening tag.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the opening tag.
     */
    private static function checkReferences(File $phpcsFile, int $stackPtr): void
    {
        if ($phpcsFile->findPrevious(T_OPEN_TAG, ($stackPtr - 1)) !== false) {
            return;
        }

        $references = [
            ...ReferencedNameHelper::getAllReferencedNames($phpcsFile, $stackPtr),
            ...ReferencedNameHelper::getAllReferencedNamesInAttributes($phpcsFile, $stackPtr),
        ];
        foreach ($references as $reference) {
            // An attribute's reference includes its brackets, so the name is the one name token in it.
            $start = $reference->getStartPointer();
            $end = $reference->getEndPointer() + 1;
            $namePtr = $phpcsFile->findNext(self::NAME_TOKENS, $start, $end);
            if (
                !$reference->isClass() || $namePtr === false
                || $phpcsFile->findNext(self::NAME_TOKENS, ($namePtr + 1), $end) !== false
            ) {
                continue;
            }

            $written = $reference->getNameAsReferencedInFile();
            $resolved = NamespaceHelper::resolveClassName($phpcsFile, $written, $namePtr);
            self::checkClassName($phpcsFile, $namePtr, $written, $resolved);
        }
    }

    /**
     * Check the names a `use` statement imports, if it imports classes.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `use` keyword.
     */
    private static function checkUseStatement(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        $end = $phpcsFile->findNext([T_SEMICOLON, T_OPEN_USE_GROUP], ($stackPtr + 1));
        if (
            $next === false || $end === false || $tokens[$end]['code'] !== T_SEMICOLON
            || in_array(strtolower($tokens[$next]['content']), ['function', 'const'], true)
            || !UseStatementHelper::isImportUse($phpcsFile, $stackPtr)
        ) {
            return;
        }

        for ($i = $next; $i < $end; $i++) {
            $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($i - 1), null, true);
            if (in_array($tokens[$i]['code'], self::NAME_TOKENS, true) && $tokens[$previous]['code'] !== T_AS) {
                $written = $tokens[$i]['content'];
                self::checkClassName($phpcsFile, $i, $written, $written);
            }
        }
    }

    /**
     * Report a name of a built-in class that isn't in the class's own case, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $namePtr The position of the name.
     * @param string $written The name as written.
     * @param string $resolved The fully qualified name it refers to.
     */
    private static function checkClassName(File $phpcsFile, int $namePtr, string $written, string $resolved): void
    {
        $real = self::builtInClasses()[strtolower(ltrim($resolved, '\\'))] ?? null;
        if ($real === null) {
            return;
        }

        // As much of the real name as is written, so `Randomizer` in `namespace Random` is matched with its last part.
        $writtenName = ltrim($written, '\\');
        $realParts = explode('\\', $real);
        $expected = implode('\\', array_slice($realParts, -count(explode('\\', $writtenName))));
        if ($writtenName === $expected || strtolower($writtenName) !== strtolower($expected)) {
            return;
        }

        $error = 'A built-in class must be written in its own case; expected %s but found %s';
        $data = [$expected, $writtenName];
        if ($phpcsFile->addFixableError($error, $namePtr, self::CODE_CLASS_NAME_CASE, $data)) {
            $prefix = str_starts_with($written, '\\') ? '\\' : '';
            $phpcsFile->fixer->replaceToken($namePtr, "{$prefix}{$expected}");
        }
    }

    /**
     * Get the classes, interfaces and enums built into the PHP running PHP_CodeSniffer, with its loaded extensions.
     *
     * @return array<string, string> The names, by lower-case name.
     */
    private static function builtInClasses(): array
    {
        if (self::$builtInClasses === null) {
            self::$builtInClasses = [];
            foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $name) {
                if (new ReflectionClass($name)->isInternal()) {
                    self::$builtInClasses[strtolower($name)] = $name;
                }
            }
        }

        return self::$builtInClasses;
    }

    // endregion

    // region Magic methods

    /**
     * Check a magic method's name where it's declared.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `function` keyword.
     */
    private static function checkMethodDeclaration(File $phpcsFile, int $stackPtr): void
    {
        // The name comes after any `&`, as in `function &__get()`.
        $opener = $phpcsFile->getTokens()[$stackPtr]['parenthesis_opener'] ?? null;
        $namePtr = is_int($opener) ? $phpcsFile->findNext(T_STRING, ($stackPtr + 1), $opener) : false;
        if ($namePtr !== false && Scopes::isOOMethod($phpcsFile, $stackPtr)) {
            self::checkMagicName($phpcsFile, $namePtr);
        }
    }

    /**
     * Check a magic method's name where it's called with `->`, `?->` or `::`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the name.
     */
    private static function checkMethodCall(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if (!str_starts_with($tokens[$stackPtr]['content'], '__')) {
            return;
        }

        $operators = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        if (
            $previous !== false && $next !== false && in_array($tokens[$previous]['code'], $operators, true)
            && $tokens[$next]['code'] === T_OPEN_PARENTHESIS
        ) {
            self::checkMagicName($phpcsFile, $stackPtr);
        }
    }

    /**
     * Report a magic method's name that isn't in its own case, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $namePtr The position of the name.
     */
    private static function checkMagicName(File $phpcsFile, int $namePtr): void
    {
        $name = $phpcsFile->getTokens()[$namePtr]['content'];
        $expected = self::MAGIC_METHODS[strtolower($name)] ?? null;
        if ($expected === null || $expected === $name) {
            return;
        }

        $error = 'A magic method must be written in its own case; expected %s but found %s';
        if ($phpcsFile->addFixableError($error, $namePtr, self::CODE_MAGIC_METHOD_CASE, [$expected, $name])) {
            $phpcsFile->fixer->replaceToken($namePtr, $expected);
        }
    }

    // endregion
}
