<?php

declare(strict_types=1);

namespace Jumbo\Tests\PHP;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.PHP.SimplifySyntax sniff.
 */
class SimplifySyntaxUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the file being tested.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        return match ($testFile) {
            'SimplifySyntaxUnitTest.1.inc' => [
                // For loops: a condition only, with spaces, none, the alternative syntax, and two with comments.
                12 => 1,
                14 => 1,
                16 => 1,
                18 => 1,
                20 => 1,
                22 => 1,
                // isset(): two, three, in a condition, after a negated one, before a comparison, a trailing comma and
                // a comment.
                32 => 1,
                33 => 1,
                34 => 1,
                36 => 1,
                37 => 1,
                38 => 1,
                39 => 1,
                // unset(): two, three on a line, and two after one in an if.
                51 => 1,
                54 => 1,
                55 => 1,
                // Nullsafe $this.
                60 => 1,
                61 => 1,
                // Binary strings.
                65 => 1,
                66 => 1,
                67 => 1,
                70 => 1,
                // Braces: on their own lines, in a case, and on one line.
                87 => 1,
                91 => 1,
                96 => 1,
                // Return: at the end, in a closure with a comment, and with a comment inside.
                117 => 1,
                121 => 1,
                125 => 1,
            ],
            // The rest of the file isn't read right until the b prefix is fixed.
            'SimplifySyntaxUnitTest.2.inc' => [3 => 1],
            default => [],
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
