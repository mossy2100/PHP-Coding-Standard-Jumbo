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
            // Every statement or case label that fits on one line, ExpressionFormat's aside.
            134 => 1,
            137 => 1,
            140 => 1,
            143 => 1,
            148 => 1,
            // The lines around a body that ExpressionFormat doesn't read, or a call or array too long to collapse.
            // A break before a body's keyword, after return.
            157 => 1,
            164 => 1,
            170 => 1,
            173 => 1,
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
