<?php

declare(strict_types=1);

namespace Jumbo\Tests\Classes;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Classes.ClassMembers sniff.
 */
class ClassMembersUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // Protected in a final class: a constant, a property, a set visibility, a promoted property and a method.
            24 => 1,
            25 => 1,
            26 => 1,
            28 => 1,
            35 => 1,
            // Static methods: one calling static methods, and one calling get_class() with an argument.
            96 => 1,
            111 => 1,
            // Stringable.
            135 => 1,
        ];
    }

    /**
     * Returns the lines where warnings should occur.
     *
     * @return array<int, int> Line number => warning count.
     */
    protected function getWarningList(): array
    {
        return [146 => 1, 151 => 1];
    }
}
