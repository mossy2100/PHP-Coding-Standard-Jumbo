<?php

declare(strict_types=1);

namespace PERCS31\Tests\PHP;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for PERCS31.PHP.CanonicalTypes sniff.
 */
class CanonicalTypesUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        return match ($testFile) {
            // Casts: long names, (binary) in each form, and other cases.
            'CanonicalTypesUnitTest.2.inc' => array_fill_keys([18, 19, 20, 23, 24, 25, 26, 29, 30], 1),
            // Doc types: long names and other cases, in each kind of tag, and inside generics.
            'CanonicalTypesUnitTest.3.inc' => [15 => 1, 16 => 2, 17 => 1, 21 => 1, 25 => 1, 26 => 2],
            default => [
                // A typed constant, a property, two parameters and a return type, and a cast.
                23 => 1,
                25 => 1,
                27 => 3,
                29 => 1,
                // A set hook's parameter: a union, a nullable type, one with a type read as a name, and one after an
                // attribute.
                37 => 2,
                41 => 1,
                47 => 2,
                52 => 1,
                // A backed enum's type.
                68 => 1,
                73 => 1,
                // Long names in a property, a parameter and a return type.
                78 => 1,
                80 => 3,
            ],
        };
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
