<?php

declare(strict_types=1);

namespace Jumbo\Helpers;

use PHP_CodeSniffer\Files\File;
use SlevomatCodingStandard\Helpers\NamespaceHelper;

/**
 * Helper class for PHPUnit test classes, shared by the Jumbo.PhpUnit sniffs.
 */
class PhpUnitHelper
{
    /**
     * The class every PHPUnit test case extends.
     */
    public const string TEST_CASE = 'PHPUnit\Framework\TestCase';

    /**
     * Check whether a class is a PHPUnit test case: it extends `PHPUnit\Framework\TestCase`, resolved through the
     * file's namespace and `use` statements.
     *
     * A class that extends a project's own base test class isn't recognised, as that would mean loading the code.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the `class` keyword.
     * @return bool True if it is.
     */
    public static function isTestCase(File $phpcsFile, int $classPtr): bool
    {
        $parent = $phpcsFile->findExtendedClassName($classPtr);
        if ($parent === false || $parent === '') {
            return false;
        }

        $resolved = ltrim(NamespaceHelper::resolveClassName($phpcsFile, $parent, $classPtr), '\\');

        return strcasecmp($resolved, self::TEST_CASE) === 0;
    }

    /**
     * Find the methods a class declares, not those of a closure or anonymous class inside it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the `class` keyword.
     * @return list<int> The position of each method's `function` keyword.
     */
    public static function findMethods(File $phpcsFile, int $classPtr): array
    {
        $tokens = $phpcsFile->getTokens();
        $opener = $tokens[$classPtr]['scope_opener'] ?? null;
        $closer = $tokens[$classPtr]['scope_closer'] ?? null;
        if (!is_int($opener) || !is_int($closer)) {
            return [];
        }

        $methods = [];
        for ($i = ($opener + 1); $i < $closer; $i++) {
            if ($tokens[$i]['code'] === T_FUNCTION) {
                $methods[] = $i;
            }

            $scopeCloser = $tokens[$i]['scope_closer'] ?? null;
            if (isset($tokens[$i]['scope_opener']) && is_int($scopeCloser) && $scopeCloser > $i) {
                $i = $scopeCloser;
            }
        }

        return $methods;
    }
}
