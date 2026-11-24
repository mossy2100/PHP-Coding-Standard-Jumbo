<?php

declare(strict_types=1);

namespace Jumbo\Tests\Operators;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Operators.IncrementStyle sniff.
 */
class IncrementStyleUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // Statements: a variable, a decrement, a property, an array element and a static property.
            11 => 1,
            12 => 1,
            13 => 1,
            14 => 1,
            15 => 1,
            // A for loop's step, and two in one step.
            16 => 1,
            17 => 2,
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
