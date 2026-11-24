<?php

declare(strict_types=1);

namespace Jumbo\Tests\PHP;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PHP.BuiltInNameCase sniff.
 */
class BuiltInNameCaseUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // use statements: an import, and one of two.
            5 => 1,
            8 => 1,
            // References: new, a fully qualified name, a static call, instanceof, an imported name and a namespaced
            // one.
            20 => 1,
            21 => 1,
            22 => 1,
            23 => 1,
            24 => 1,
            25 => 1,
            26 => 1,
            // Type declarations, a catch and an attribute.
            28 => 3,
            31 => 1,
            35 => 1,
            // Magic methods: declarations, and calls with ::, ?-> and &.
            65 => 1,
            67 => 1,
            70 => 1,
            72 => 2,
            75 => 1,
            79 => 1,
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
