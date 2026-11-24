<?php

declare(strict_types=1);

namespace Jumbo\Tests\NamingConventions;

use PHP_CodeSniffer\Tests\Standards\AbstractSniffTestCase;

/**
 * Unit tests for Jumbo.NamingConventions.ValidNamespaceName sniff.
 */
class ValidNamespaceNameUnitTest extends AbstractSniffTestCase
{
    /**
     * Returns the lines where errors should occur.
     *
     * @param string $testFile The name of the test input file.
     * @return array<int, int> Line number => error count.
     */
    protected function getErrorList(string $testFile = ''): array
    {
        // A look-alike letter in a qualified name, and an accented one in a single-part name.
        return $testFile === 'ValidNamespaceNameUnitTest.1.inc' ? [] : [4 => 1];
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
