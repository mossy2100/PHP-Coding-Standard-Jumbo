<?php

declare(strict_types=1);

namespace Jumbo\Tests\TypeHints;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.TypeHints.DeclareStrictTypes sniff.
 */
class DeclareStrictTypesUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        return match ($testFile) {
            // strict_types=0, between PHP tags on the first line of a file with markup.
            'DeclareStrictTypesUnitTest.2.inc' => [1 => 1],
            // A block declare, then an alternative syntax one: the fix adds a separate statement above it.
            'DeclareStrictTypesUnitTest.3.inc', 'DeclareStrictTypesUnitTest.6.inc' => [1 => 1],
            // Another declare statement: the fix adds a separate statement above it, not a directive to it.
            'DeclareStrictTypesUnitTest.4.inc' => [1 => 1],
            // A block declare after a shebang line: the fix adds a separate statement above it.
            'DeclareStrictTypesUnitTest.7.inc' => [2 => 1],
            // Markup before the opening tag, with a block declare, with no declare, and with another declare: the fix
            // adds the declaration between its own PHP tags on the first line.
            'DeclareStrictTypesUnitTest.8.inc', 'DeclareStrictTypesUnitTest.9.inc', 'DeclareStrictTypesUnitTest.10.inc'
                => [2 => 1],
            // Markup after the PHP: the fix adds the declaration on the first line too.
            'DeclareStrictTypesUnitTest.14.inc' => [1 => 1],
            // A shebang line, with markup before the PHP, then after it: the fix adds the declaration on the second
            // line.
            'DeclareStrictTypesUnitTest.15.inc' => [3 => 1],
            'DeclareStrictTypesUnitTest.16.inc' => [2 => 1],
            // A template with only <?= tags: the fix adds the declaration on the first line.
            'DeclareStrictTypesUnitTest.19.inc' => [1 => 1],
            // No declare statement: the fix adds one after the file docblock, before a namespace, and before code on
            // the opening tag's line.
            'DeclareStrictTypesUnitTest.21.inc',
            'DeclareStrictTypesUnitTest.22.inc',
            'DeclareStrictTypesUnitTest.23.inc',
                => [1 => 1],
            // strict_types=0, after another directive.
            'DeclareStrictTypesUnitTest.24.inc' => [3 => 1],
            // The declaration between PHP tags on the first line of a file with markup, with and without the `;`, and
            // with a comment, and after a shebang line (17). Then strict_types=1 with another declare statement after
            // it (11, 12), and before it (13), with spaces around the = (5), and as STRICT_TYPES (18), which
            // PERCS31.Files.DeclareStatement reports.
            default => [],
        };
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
