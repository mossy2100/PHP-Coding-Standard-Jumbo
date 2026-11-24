<?php

declare(strict_types=1);

namespace Jumbo\Tests\Commenting;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Commenting.PhpDocTags sniff.
 */
class PhpDocTagsUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // Where the tags are: other tags are allowed anywhere.
        if ($testFile === 'PhpDocTagsUnitTest.4.inc') {
            // @throws before @return, @return before a @param, a @param out of order and apart, and a blank line
            // between @param tags.
            return [37 => 1, 38 => 1, 39 => 2, 50 => 1];
        }

        // @var tags.
        if ($testFile === 'PhpDocTagsUnitTest.3.inc') {
            return [
                // A property's redundant name, a wrong type, a one-line comment without spaces, and spacing.
                8 => 1,
                11 => 1,
                14 => 1,
                17 => 1,
                // No type.
                23 => 1,
                // An inline @var with the name first, and one in a block comment.
                31 => 1,
                34 => 1,
            ];
        }

        // A function's @param and @return tags.
        if ($testFile === 'PhpDocTagsUnitTest.2.inc') {
            return [
                // A wrong type, spacing, unknown and repeated names, a missing type, and an alias with a wrong type.
                39 => 1,
                40 => 1,
                41 => 1,
                42 => 1,
                43 => 1,
                44 => 2,
                // Malformed self-references.
                49 => 1,
                54 => 1,
                // A @return with nothing after it.
                59 => 1,
                // Spaces after the tag.
                64 => 1,
                65 => 1,
            ];
        }

        // Tag names and inline tags.
        return [
            // Casing, in a block tag.
            32 => 1,
            33 => 1,
            34 => 1,
            35 => 1,
            36 => 1,
            39 => 1,
            // Aliases, in a block tag.
            44 => 1,
            45 => 1,
            46 => 1,
            47 => 1,
            48 => 1,
            49 => 1,
            50 => 1,
            51 => 1,
            // Casing and aliases, in inline tags.
            57 => 4,
            // Inline format.
            63 => 1,
            64 => 1,
            65 => 1,
            66 => 1,
            67 => 1,
            68 => 1,
            69 => 1,
            70 => 1,
            // Inline format, with the casing fixed on the next pass.
            76 => 1,
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
