<?php

declare(strict_types=1);

namespace Jumbo\Tests\Functions;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Functions.RequireArrowFunction sniff.
 */
class RequireArrowFunctionUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // The closure nested in one using an outer variable, which reads its use list from that closure's scope.
            29 => 1,
            // Only parameters, use variables, $this and superglobals.
            48 => 1,
            51 => 1,
            54 => 1,
            57 => 1,
            // Ending the expression: a ternary, an argument and an array.
            62 => 1,
            65 => 1,
            68 => 1,
            // A closure returning a closure, and the nested one.
            73 => 1,
            74 => 1,
            // A comment the fix would remove: reported, not fixed.
            80 => 1,
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
