<?php

declare(strict_types=1);

namespace Jumbo\Tests\Classes;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.Classes.MagicMethods sniff.
 */
class MagicMethodsUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        // A class with __toString() and no parent or interface.
        return [21 => 1];
    }

    /**
     * Returns the lines where warnings should occur.
     *
     * @return array<int, int> Line number => warning count.
     */
    protected function getWarningList(): array
    {
        // __sleep() and __wakeup().
        return [32 => 1, 37 => 1];
    }
}
