<?php

declare(strict_types=1);

namespace Jumbo\Tests\PHP;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PHP.DisallowInvisibleCharacters sniff.
 */
class DisallowInvisibleCharactersUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // With allowTabs, only the tab in a string.
        if ($testFile === 'DisallowInvisibleCharactersUnitTest.2.inc') {
            return [6 => 1];
        }

        // Strings, code, comments, a nowdoc, a heredoc, a name and inline HTML: one character each.
        return array_fill_keys([8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 19, 22, 24, 25, 27], 1);
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
