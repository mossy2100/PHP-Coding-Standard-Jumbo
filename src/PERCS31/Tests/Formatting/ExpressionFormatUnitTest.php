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
            return [12 => 1, 13 => 1, 16 => 1, 20 => 1, 21 => 1, 24 => 1, 25 => 1, 28 => 1, 30 => 1]
                // In strict: a split call that fits, chopped when it should be, and a split call in an expression that
                // fits.
                + [35 => 1, 39 => 1, 49 => 1]
                // Hugging: closures, which never are, a call, a named argument, an expression that fits on its own
                // line, and one that doesn't, a ternary, a chain, an arrow function, an anonymous class, and a string.
                + [56 => 1, 65 => 1, 72 => 1, 73 => 1, 74 => 1, 75 => 1, 76 => 1, 77 => 1, 78 => 1, 79 => 1, 83 => 2]
                // Pairs: a value with operators on its own line, a split key, no room after `key =>`, and an array.
                + [88 => 1, 89 => 1, 90 => 1, 92 => 1]
                // Arms with several conditions: a value on its own line, conditions too long, and conditions that fit.
                + [98 => 1, 99 => 1, 100 => 1]
                // Ternaries: too long, with a call, short, in a group, and one that fits.
                + [105 => 1, 106 => 1, 107 => 1, 108 => 1, 109 => 1]
                // Arrow functions and a yield pair, empty brackets, and a destructuring list.
                + [116 => 1, 118 => 1, 119 => 1, 120 => 1, 121 => 1, 123 => 1]
                // Nested arrow functions.
                + [133 => 1, 134 => 1, 135 => 1, 136 => 1]
                // Assignments.
                + [140 => 1, 141 => 1, 142 => 1, 144 => 1, 147 => 1, 150 => 1]
                // Chains.
                + [153 => 1, 154 => 1, 155 => 1, 156 => 1, 157 => 1, 158 => 1]
                // A line break before a call's bracket, and echo lists.
                + [163 => 1, 165 => 1, 167 => 1, 169 => 1];
        }

        if ($testFile === 'ExpressionFormatUnitTest.6.inc') {
            // Heredocs and nowdocs, at a line length of 40.
            return [15 => 1, 16 => 1, 17 => 1, 18 => 1, 21 => 1];
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
            43 => 1,
            45 => 1,
            47 => 1,
            49 => 1,
            53 => 1,
            // Split at a tighter operator, or not split at all.
            60 => 1,
            67 => 1,
            68 => 1,
            // A line broken before an operand that fits on it.
            70 => 1,
            // An operand split while the level around it isn't.
            73 => 1,
            75 => 1,
        ];
    }
}
