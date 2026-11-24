<?php

declare(strict_types=1);

namespace Jumbo\Tests\Commenting;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Commenting.DisallowHashComments sniff.
 */
class DisallowHashCommentsUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // The exceptions property cleared, then set to a custom list.
        if ($testFile === 'DisallowHashCommentsUnitTest.2.inc') {
            return [5 => 1, 6 => 1, 12 => 1];
        }

        // The default exceptions, region and endregion.
        return [
            // Text after a space, and straight after the hash.
            20 => 1,
            21 => 1,
            // Not a whole exception word, the wrong case, and a space before the word.
            22 => 1,
            23 => 1,
            24 => 1,
            // After code, alone, two hashes, and a tab after the hash.
            25 => 1,
            26 => 1,
            27 => 1,
            28 => 1,
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
