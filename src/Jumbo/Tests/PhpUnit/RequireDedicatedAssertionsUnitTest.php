<?php

declare(strict_types=1);

namespace Jumbo\Tests\PhpUnit;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PhpUnit.RequireDedicatedAssertions sniff.
 */
class RequireDedicatedAssertionsUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // The parent's assertSame() with null.
            22 => 1,
            // assertTrue() and assertFalse() of checks, through $this, self and static, with a message, two arguments,
            // a fully qualified function, and is_nan().
            23 => 1,
            24 => 1,
            25 => 1,
            26 => 1,
            27 => 1,
            28 => 1,
            29 => 1,
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
