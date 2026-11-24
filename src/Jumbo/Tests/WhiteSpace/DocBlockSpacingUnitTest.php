<?php

declare(strict_types=1);

namespace Jumbo\Tests\WhiteSpace;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.WhiteSpace.DocBlockSpacing sniff.
 */
class DocBlockSpacingUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [
            // A global constant, a class, a property, a class constant, a method, an enum and an enum case.
            31 => 1,
            35 => 1,
            39 => 1,
            43 => 1,
            47 => 1,
            52 => 1,
            56 => 1,
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
