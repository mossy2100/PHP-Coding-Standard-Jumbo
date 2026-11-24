<?php

declare(strict_types=1);

namespace Jumbo\Tests\PHP;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PHP.FunctionCalls sniff.
 */
class FunctionCallsUnitTest extends AbstractSniffTestCase
{
    /**
     * Set the project's lowest PHP version: 8.5 for the first fixture, for array_first() and array_last(), and unset
     * for the second.
     *
     * @param string $testFile The name of the test input file.
     * @param Config $config The config data for the run.
     */
    public function setCliValues(string $testFile, Config $config): void
    {
        $config->setConfigData('php_version', $testFile === 'FunctionCallsUnitTest.inc' ? '80500' : null, true);
    }

    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // Which functions are PHP's: an imported global one, and a fully qualified call, but not one imported from
        // elsewhere or declared in the file.
        if ($testFile === 'FunctionCallsUnitTest.2.inc') {
            return [18 => 1, 19 => 1, 22 => 1];
        }

        return [
            // Casts, but not intval() with a base.
            3 => 1,
            5 => 1,
            // settype().
            6 => 1,
            7 => 1,
            // Aliases, die and a print statement, but not a print whose value is used.
            8 => 1,
            9 => 1,
            10 => 1,
            11 => 1,
            // ini_set() with a dedicated function (as a statement, and not), ini_alter(), and a cookie setting.
            13 => 1,
            14 => 1,
            16 => 1,
            17 => 1,
            // strpos(), but not inside arithmetic.
            18 => 1,
            19 => 1,
            20 => 1,
            // substr() and strncmp(), but not substr() with a variable needle for an ending.
            22 => 1,
            23 => 1,
            25 => 1,
            // strlen().
            26 => 1,
            27 => 1,
            // array_push() with one value, implode(), sprintf() and printf(), but not sprintf() with a %.
            28 => 1,
            30 => 1,
            31 => 1,
            33 => 1,
            // pow().
            34 => 1,
            35 => 1,
            36 => 1,
            // call_user_func() and call_user_func_array(), but not one that changes its callable.
            37 => 1,
            38 => 1,
            39 => 1,
            40 => 1,
            // array_first() and array_last(), but not for another array.
            44 => 1,
            45 => 1,
        ];
    }

    /**
     * Returns the lines where warnings should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => warning count.
     */
    protected function getWarningList(string $testFile = ''): array
    {
        // fopen()'s text mode.
        return $testFile === 'FunctionCallsUnitTest.2.inc' ? [] : [42 => 1];
    }
}
