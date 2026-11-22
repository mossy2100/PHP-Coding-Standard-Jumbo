<?php

declare(strict_types=1);

namespace Jumbo\Tests\Commenting;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Commenting.NormalizePhpDocTags sniff.
 */
class NormalizePhpDocTagsUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
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
