<?php

declare(strict_types=1);

namespace Jumbo\Tests\PHP;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PHP.IntegerLiteral sniff.
 */
class IntegerLiteralUnitTest extends AbstractSniffTestCase
{
    /**
     * Set the project's lowest PHP version: 8.1 for the first fixture, for the 0o prefix, and unset for the second.
     *
     * @param string $testFile The name of the test input file.
     * @param Config $config The config data for the run.
     */
    public function setCliValues(string $testFile, Config $config): void
    {
        $config->setConfigData('php_version', $testFile === 'IntegerLiteralUnitTest.1.inc' ? '80100' : null, true);
    }

    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // The case of a prefix and of hex digits.
        $errors = [14 => 1, 15 => 1, 16 => 1, 17 => 1, 18 => 1];

        // A legacy octal, with php_version 8.1 or later.
        return $testFile === 'IntegerLiteralUnitTest.1.inc' ? $errors + [21 => 1, 22 => 1] : $errors;
    }

    /**
     * Returns the lines where warnings should occur.
     *
     * @return array<int, int> Line number => warning count.
     */
    protected function getWarningList(): array
    {
        return [];
    }
}
