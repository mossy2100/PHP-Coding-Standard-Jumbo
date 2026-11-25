<?php

declare(strict_types=1);

namespace Jumbo\Tests\PHP;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PHP.RemoveUselessCode sniff.
 */
class RemoveUselessCodeUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the file being tested.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // The rest of the file isn't read right until the b prefix is fixed.
        if ($testFile === 'RemoveUselessCodeUnitTest.2.inc') {
            return [3 => 1];
        }

        return [
            // Nullsafe $this.
            6 => 1,
            7 => 1,
            // Binary strings.
            11 => 1,
            12 => 1,
            13 => 1,
            16 => 1,
            // Braces: on their own lines, inside a case body, and on one line.
            39 => 1,
            43 => 1,
            48 => 1,
            // Return: at the end, in a closure with a comment, and with a comment inside.
            69 => 1,
            73 => 1,
            77 => 1,
        ];
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
