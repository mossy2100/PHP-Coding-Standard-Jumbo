<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PhpUnit;

use Jumbo\Helpers\FunctionCallHelper;
use Jumbo\Helpers\PhpUnitHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use PhpCollective\Sniffs\Testing\AssertPrimitivesSniff;

/**
 * Requires PHPUnit's dedicated assertions: `assertNull($a)`, not `assertSame(null, $a)`, and `assertIsInt($a)`, not
 * `assertTrue(is_int($a))`, for clearer failure messages.
 *
 * PER Coding Style 3.1 has no rules for tests, so this is an extra rule. This extends
 * PhpCollective.Testing.AssertPrimitives, which reports `assertSame()` with `null`, `true` or `false`, as
 * InvalidAssert, and adds php-cs-fixer's `php_unit_dedicate_assert`, as DedicatedAssertion: `assertTrue()` or
 * `assertFalse()` of a check that has its own assertion, such as `is_int()` for `assertIsInt()` or `assertIsNotInt()`,
 * or `array_key_exists()` for `assertArrayHasKey()`. "Failed asserting that 'abc' is of type int" says more than
 * "Failed asserting that false is true". Only exact equivalents are fixed: `is_nan()` and `is_finite()` have no
 * negative, `is_resource()` differs on a closed resource, and `empty()` and `in_array()` aren't checked.
 *
 * A test class is one that extends `PHPUnit\Framework\TestCase`, so the parent's limit to files named `*Test.php` is
 * lifted. The new check covers `self::` and `static::` calls too, as well as `$this->`.
 */
class RequireDedicatedAssertionsSniff extends AssertPrimitivesSniff
{
    public const string CODE_DEDICATED_ASSERTION = 'DedicatedAssertion';

    /**
     * The checks with their own assertions, by lower-case name: the assertion for `assertTrue()`, and for
     * `assertFalse()`, or null if there's no exact one.
     *
     * @var array<string, array{string, ?string}>
     */
    private const array ASSERTIONS = [
        'is_array' => ['assertIsArray', 'assertIsNotArray'],
        'is_bool' => ['assertIsBool', 'assertIsNotBool'],
        'is_callable' => ['assertIsCallable', 'assertIsNotCallable'],
        'is_float' => ['assertIsFloat', 'assertIsNotFloat'],
        'is_int' => ['assertIsInt', 'assertIsNotInt'],
        'is_iterable' => ['assertIsIterable', 'assertIsNotIterable'],
        'is_null' => ['assertNull', 'assertNotNull'],
        'is_numeric' => ['assertIsNumeric', 'assertIsNotNumeric'],
        'is_object' => ['assertIsObject', 'assertIsNotObject'],
        'is_scalar' => ['assertIsScalar', 'assertIsNotScalar'],
        'is_string' => ['assertIsString', 'assertIsNotString'],
        'is_nan' => ['assertNan', null],
        'is_finite' => ['assertFinite', null],
        'is_infinite' => ['assertInfinite', null],
        'file_exists' => ['assertFileExists', 'assertFileDoesNotExist'],
        'is_dir' => ['assertDirectoryExists', 'assertDirectoryDoesNotExist'],
        'is_readable' => ['assertIsReadable', 'assertIsNotReadable'],
        'is_writable' => ['assertIsWritable', 'assertIsNotWritable'],
        'array_key_exists' => ['assertArrayHasKey', 'assertArrayNotHasKey'],
    ];

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `function` keyword.
     */
    // phpcs:ignore Jumbo.TypeHints.ParameterTypeHint.MissingNativeTypeHint -- The parent's $stackPtr has no type.
    public function process(File $phpcsFile, $stackPtr): void
    {
        if (!$this->isTest($phpcsFile, $stackPtr)) {
            return;
        }

        $this->assertSameUsage($phpcsFile, $stackPtr);
        self::checkDedicatedAssertions($phpcsFile, $stackPtr);
    }

    /**
     * Check whether a method is in a PHPUnit test class.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `function` keyword.
     * @return bool True if it is.
     */
    protected function isTest(File $phpcsFile, int $stackPtr): bool
    {
        foreach ($phpcsFile->getTokens()[$stackPtr]['conditions'] as $ptr => $code) {
            if ($code === T_CLASS && PhpUnitHelper::isTestCase($phpcsFile, $ptr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Report `assertTrue()` or `assertFalse()` of a check with its own assertion, in a method, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `function` keyword.
     */
    private static function checkDedicatedAssertions(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $opener = $tokens[$stackPtr]['scope_opener'] ?? null;
        $closer = $tokens[$stackPtr]['scope_closer'] ?? null;
        if (!is_int($opener) || !is_int($closer)) {
            return;
        }

        for ($i = ($opener + 1); $i < $closer; $i++) {
            $name = strtolower($tokens[$i]['content']);
            if ($tokens[$i]['code'] === T_STRING && ($name === 'asserttrue' || $name === 'assertfalse')) {
                self::checkAssertion($phpcsFile, $i, $name === 'asserttrue');
            }
        }
    }

    /**
     * Report one `assertTrue()` or `assertFalse()` of a check with its own assertion, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $methodPtr The position of the assertion's name.
     * @param bool $isTrue Whether it's `assertTrue()`.
     */
    private static function checkAssertion(File $phpcsFile, int $methodPtr, bool $isTrue): void
    {
        $tokens = $phpcsFile->getTokens();
        $operator = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($methodPtr - 1), null, true);
        $object = $operator === false
            ? false
            : $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($operator - 1), null, true);
        $opener = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($methodPtr + 1), null, true);
        if (
            $operator === false || $object === false || $opener === false
            || !self::isOwnCall($tokens, $operator, $object)
        ) {
            return;
        }

        $check = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($opener + 1), null, true);
        $function = $check === false ? null : FunctionCallHelper::globalFunctionName($phpcsFile, $check);
        $assertion = $function === null ? null : (self::ASSERTIONS[$function][$isTrue ? 0 : 1] ?? null);
        if ($check === false || $assertion === null) {
            return;
        }

        // The check must be the whole first argument.
        $checkOpener = FunctionCallHelper::opener($phpcsFile, $check);
        $checkCloser = $tokens[$checkOpener]['parenthesis_closer'];
        $after = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($checkCloser + 1), null, true);
        $arguments = FunctionCallHelper::arguments($phpcsFile, $check);
        $expected = $function === 'array_key_exists' ? 2 : 1;
        if (
            $after === false || !in_array($tokens[$after]['code'], [T_COMMA, T_CLOSE_PARENTHESIS], true)
            || $arguments === null || count($arguments) !== $expected
        ) {
            return;
        }

        $error = 'Use %s(), not %s(%s())';
        $data = [$assertion, $tokens[$methodPtr]['content'], $tokens[$check]['content']];
        if (!$phpcsFile->addFixableError($error, $methodPtr, self::CODE_DEDICATED_ASSERTION, $data)) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($methodPtr, $assertion);
        for ($i = $check; $i <= $checkOpener; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->replaceToken($checkCloser, '');
        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Check whether a call is to the test class's own method: `$this->`, `self::` or `static::`.
     *
     * @param array<int, array<string, mixed>> $tokens The file's tokens.
     * @param int $operator The position of the `->` or `::`.
     * @param int $object The position of what comes before it.
     * @return bool True if it is.
     */
    private static function isOwnCall(array $tokens, int $operator, int $object): bool
    {
        if ($tokens[$operator]['code'] === T_OBJECT_OPERATOR) {
            return $tokens[$object]['code'] === T_VARIABLE && $tokens[$object]['content'] === '$this';
        }

        return $tokens[$operator]['code'] === T_DOUBLE_COLON
            && in_array($tokens[$object]['code'], [T_SELF, T_STATIC], true);
    }
}
