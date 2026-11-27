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
            93 => 1,
            96 => 1,
            99 => 1,
            102 => 1,
            104 => 1,
            106 => 1,
            110 => 1,
            114 => 1,
            119 => 1,
            // The lines around a body, or a call or array too long to collapse.
            125 => 1,
            129 => 1,
            132 => 1,
            134 => 1,
            138 => 1,
            145 => 1,
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
