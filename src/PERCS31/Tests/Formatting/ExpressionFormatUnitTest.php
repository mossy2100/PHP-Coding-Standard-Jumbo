<?php

declare(strict_types=1);

namespace PERCS31\Tests\Formatting;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for PERCS31.Formatting.ExpressionFormat sniff.
 */
class ExpressionFormatUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(): array
    {
        return [];
    }

    /**
     * Returns the lines where warnings should occur.
     *
     * @param string $testFile The name of the file being tested.
     * @return array<int, int> Line number => warning count.
     */
    protected function getWarningList(string $testFile = ''): array
    {
        if ($testFile === 'ExpressionFormatUnitTest.5.inc') {
            // Calls and arrays too long, at a line length of 40: chopped down, hugged, outermost first, and an argument
            // on its own line.
            return [12 => 1, 13 => 1, 16 => 1, 19 => 1, 20 => 1, 23 => 1, 24 => 1, 27 => 1, 29 => 1]
                // In strict: a split call that fits, hugging when chopping lets the array fit, chopped when it should
                // hug, and a split call in an expression that fits.
                + [34 => 1, 38 => 1, 41 => 1, 48 => 1]
                // A chopped closure that should be hugged, two closures hugged, and a hugged match.
                + [60 => 1, 66 => 1, 71 => 1]
                // Pairs: a value with operators on its own line, a split key, no room after `key =>`, and an array.
                + [78 => 1, 79 => 1, 80 => 1, 82 => 1]
                // Arms with several conditions: a value on its own line, conditions too long, and conditions that fit.
                + [88 => 1, 89 => 1, 90 => 1]
                // Ternaries: too long, with a call, short, in a group, and one that fits.
                + [95 => 1, 96 => 1, 97 => 1, 98 => 1, 99 => 1]
                // Arrow functions and a yield pair, empty brackets, and a destructuring list.
                + [106 => 1, 108 => 1, 109 => 1, 110 => 1, 111 => 1, 113 => 1];
        }

        if ($testFile === 'ExpressionFormatUnitTest.4.inc') {
            // Not strict: only the expression written on one line that's too long.
            return [10 => 1];
        }

        if ($testFile === 'ExpressionFormatUnitTest.2.inc') {
            // String expressions, from line 20 to line 46, then too long from line 49, then string conversions.
            $lines = [...range(20, 46), 49, 50, 51, 52, 57, 60, 63, 65, 67];

            return array_fill_keys($lines, 1) + [64 => 2];
        }

        if ($testFile === 'ExpressionFormatUnitTest.3.inc') {
            // An array split over lines is one expression, reported at its start. A value that fits on a line of its
            // own: a string, one that becomes one string (and its string), and a match arm's. A key too long for its
            // line. An array of pairs that fits on one line, split before or after their `=>`.
            return [28 => 1, 30 => 1, 34 => 1, 39 => 1, 40 => 1, 44 => 1];
        }

        return [
            // Fits on one line, an arrow function too.
            44 => 1,
            46 => 1,
            48 => 1,
            50 => 1,
            54 => 1,
            // Split at a tighter operator, or not split at all.
            61 => 1,
            68 => 1,
            69 => 1,
            // A line broken before an operand that fits on it.
            71 => 1,
            // An operand split while the level around it isn't.
            74 => 1,
            76 => 1,
        ];
    }
}
