<?php

declare(strict_types=1);

namespace Jumbo\Tests\PhpUnit;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PhpUnit.TestClass sniff.
 */
class TestClassUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // Class annotations.
            11 => 1,
            12 => 1,
            // Public hooks.
            16 => 1,
            18 => 1,
            // @test without the prefix.
            23 => 1,
            // A data provider, and a dependency on the renamed method.
            30 => 1,
            31 => 1,
            // @test with the prefix already, and @testWith over two lines.
            36 => 1,
            37 => 1,
            // @covers on a method (not fixable).
            43 => 1,
            // Requirements and a backup setting, beside another attribute.
            50 => 1,
            51 => 1,
            52 => 1,
            // A value that can't be read (not fixable).
            59 => 1,
            // An annotation whose attribute is already there.
            64 => 1,
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
