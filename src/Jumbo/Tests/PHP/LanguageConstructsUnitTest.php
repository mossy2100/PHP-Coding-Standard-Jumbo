<?php

declare(strict_types=1);

namespace Jumbo\Tests\PHP;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PHP.LanguageConstructs sniff.
 */
class LanguageConstructsUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the file being tested.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // isset(): two, three, in a condition, after a negated one, before a comparison, a trailing comma and a
        // comment. unset(): two, three on a line, and two after one in an if.
        if ($testFile === 'LanguageConstructsUnitTest.2.inc') {
            return [10 => 1, 11 => 1, 12 => 1, 14 => 1, 15 => 1, 16 => 1, 17 => 1, 29 => 1, 32 => 1, 33 => 1];
        }

        // die, in either case, and a print statement.
        return [4 => 1, 5 => 1, 6 => 1];
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
