<?php

declare(strict_types=1);

namespace Jumbo\Tests\Formatting;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Formatting.RequireSingleLineStatement sniff.
 */
class RequireSingleLineStatementUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // Every statement or case label that fits on one line.
            98 => 1,
            101 => 1,
            104 => 1,
            107 => 1,
            111 => 1,
            115 => 1,
            120 => 1,
            // The lines around a body, or a call or array too long to collapse.
            126 => 1,
            130 => 1,
            133 => 1,
            135 => 1,
            139 => 1,
            146 => 1,
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
