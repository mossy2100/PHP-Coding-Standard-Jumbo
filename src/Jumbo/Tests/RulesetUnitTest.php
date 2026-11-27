<?php

declare(strict_types=1);

namespace Jumbo\Tests;

use PERCS31\Tests\RunsToolsTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the full Jumbo and PERCS31 rulesets, and of sniff properties that can only be set in a ruleset.
 *
 * The per-sniff tests run each sniff alone. These run phpcs and phpcbf as a user would, so they catch conflicts
 * between sniffs, such as two fixers undoing each other's changes.
 */
#[CoversNothing]
class RulesetUnitTest extends TestCase
{
    use RunsToolsTrait;

    /**
     * The fixture directory.
     */
    private const string FIXTURES = __DIR__ . '/Ruleset';

    /**
     * The option that turns off Squiz.Classes.ClassFileName, for a test that runs Jumbo on a fixture or a temporary
     * file, whose name never matches the class in it.
     */
    private const string EXCLUDE_CLASS_FILE_NAME = '--exclude=Squiz.Classes.ClassFileName';

    #region Full ruleset tests.

    /**
     * Test that code following the standard has no violations.
     */
    public function testGoodFixtureHasNoViolations(): void
    {
        $good = self::FIXTURES . '/Good.inc';
        $result = $this->runTool('phpcs', ['--standard=Jumbo', '--report=json', self::EXCLUDE_CLASS_FILE_NAME, $good]);

        $this->assertSame(0, $result['exitCode'], $result['output']);
    }

    /**
     * Test that the bad fixture has violations, so the phpcbf test below has something to fix.
     */
    public function testBadFixtureHasViolations(): void
    {
        $result = $this->runTool('phpcs', ['--standard=Jumbo', '--report=json', self::FIXTURES . '/Bad.inc']);

        $this->assertNotSame(0, $result['exitCode']);
    }

    /**
     * Test that phpcbf fixes every violation in the bad fixture, giving exactly the good fixture.
     */
    public function testPhpcbfFixesBadFixtureToGood(): void
    {
        $file = $this->copyToTempFile(self::FIXTURES . '/Bad.inc');

        try {
            $this->runTool('phpcbf', ['--standard=Jumbo', $file]);
            $this->assertSame(file_get_contents(self::FIXTURES . '/Good.inc'), file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * Test that PERCS31.Formatting.ExpressionFormat and the sniffs that check the same lines agree: phpcbf fixes the
     * bad fixture to exactly the good one, without failing to finish, and the good one has no violations.
     *
     * ExpressionFormat works out each new line's indent for the layout it writes, and
     * PERCS31.Operators.OperatorPlacement checks the indent of the layout as it's written. A difference between their
     * rules would leave them undoing each other's fixes. The fixture covers the places where those rules differ:
     * conditions, a foreach header, groups, an argument, an arrow function, a match arm and a ternary.
     */
    public function testExpressionFormatAgreesWithTheOtherSniffs(): void
    {
        $good = self::FIXTURES . '/ExpressionFormatGood.inc';
        $file = $this->copyToTempFile(self::FIXTURES . '/ExpressionFormatBad.inc');

        try {
            $fixer = $this->runTool('phpcbf', ['--standard=Jumbo', $file]);
            $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
            $this->assertSame(file_get_contents($good), file_get_contents($file));
        } finally {
            unlink($file);
        }

        $result = $this->runTool('phpcs', ['--standard=Jumbo', '--report=emacs', self::EXCLUDE_CLASS_FILE_NAME, $good]);
        $this->assertSame(0, $result['exitCode'], $result['output']);
    }

    /**
     * Test that Jumbo turns a PHPUnit annotation into its attribute, imported by
     * SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly, and a @test annotation into a test prefix.
     */
    public function testPhpUnitAnnotationsBecomeImportedAttributes(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Vendor\Tests;

            use PHPUnit\Framework\TestCase;

            final class CalculatorTest extends TestCase
            {
                /**
                 * Adds.
                 *
                 * @test
                 * @dataProvider provideCases
                 */
                public function addsNumbers(int $a): void
                {
                    $this->assertSame($a, $a);
                }

                /** @return list<array{int}> */
                public static function provideCases(): array
                {
                    return [[1]];
                }
            }
            PHP);

        try {
            $this->runTool('phpcbf', ['--standard=Jumbo', self::EXCLUDE_CLASS_FILE_NAME, $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $this->assertStringContainsString("use PHPUnit\\Framework\\Attributes\\DataProvider;\n", $fixed);
        $expected = <<<'TEXT'
                /**
                 * Adds.
                 */
                #[DataProvider('provideCases')]
                public function testAddsNumbers(int $a): void

            TEXT;
        $this->assertStringContainsString($expected, $fixed);
    }

    /**
     * Test that Jumbo.PHP.DisallowInvisibleCharacters agrees with the tab indent and line ending sniffs, which report
     * some of the same characters: a tab in code becomes spaces to the next tab stop, a carriage return before a line
     * feed is removed, and a tab in a string becomes an escape.
     */
    public function testInvisibleCharacterFixesAgreeWithTheOtherSniffs(): void
    {
        $source = <<<TEXT
            <?php\r
            \r
            declare(strict_types=1);\r
            \r
            function foo(): string\r
            {\r
            \t\$a = 1;\t// One.\r
            \r
            \treturn "x\ty{\$a}";\r
            }\r

            TEXT;
        $expected = <<<TEXT
            <?php

            declare(strict_types=1);

            function foo(): string
            {
                \$a = 1; // One.

                return "x\\ty{\$a}";
            }

            TEXT;
        $file = $this->copyToTempFile(null, $source);

        try {
            $fixer = $this->runTool('phpcbf', ['--standard=Jumbo', $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
        $this->assertSame($expected, $fixed);
    }

    #endregion

    #region PERCS31 rules in Jumbo tests.

    /**
     * Test that a control structure with no body needs braces in Jumbo too, an `else` included, and that the `while`
     * of a `do` loop isn't taken for one.
     */
    public function testStructureWithNoBodyNeedsBraces(): void
    {
        $source = <<<'TEXT'
            <?php

            declare(strict_types=1);

            while (next($items));

            do {
                run();
            } while ($a);

            if ($a) {
                run();
            } else;

            TEXT;
        $file = $this->copyToTempFile(null, $source);

        try {
            $result = $this->runTool('phpcs', ['-s', '--standard=Jumbo', '--report=emacs', $file]);
            $this->runTool('phpcbf', ['--standard=Jumbo', $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $this->assertSame(2, substr_count($result['output'], 'ControlStructureEmptyStatement'), $result['output']);
        $this->assertStringContainsString("} else {}\n", $fixed);
        $this->assertMatchesRegularExpression('/while \(next\(\$items\)\) \{\s*\}\n\ndo \{/', $fixed);
        $this->assertStringContainsString("} while (\$a);\n", $fixed);
    }

    /**
     * Test that Jumbo's comment rules report a comment's indent and spacing, which PERCS31 doesn't check.
     */
    public function testCommentsAreCheckedInJumbo(): void
    {
        $source = <<<'TEXT'
            <?php

            declare(strict_types=1);

            function foo(): int
            {
            // At column 1.
                /*
              Too little.
                */
                $a = 1;// No space.
                //No space.

                return $a;
            }

            TEXT;
        $file = $this->copyToTempFile(null, $source);

        try {
            $result = $this->runTool('phpcs', ['-s', '--standard=Jumbo', '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $expected = [
            ':7:' => 'PERCS31.WhiteSpace.ScopeIndent',
            ':9:' => 'Jumbo.Commenting.BlockComment.ContentIndent',
            ':11:' => 'Jumbo.Commenting.TrailingComment.SpacingBefore',
            ':12:' => 'Squiz.Commenting.InlineComment.NoSpaceBefore',
        ];
        foreach ($expected as $line => $code) {
            $pattern = "/{$line}\\d+: error - .*" . preg_quote($code, '/') . '/';
            $this->assertMatchesRegularExpression($pattern, $result['output']);
        }
    }

    /**
     * Test that a space or line break inside an empty array's brackets is reported once in Jumbo too.
     */
    public function testSpaceInEmptyArrayIsReportedOnce(): void
    {
        $source = <<<'TEXT'
            <?php

            declare(strict_types=1);

            echo count([ ]), count([   ]), count(foo([ ]));
            echo count([
            ]);

            TEXT;
        $file = $this->copyToTempFile(null, $source);

        try {
            $result = $this->runTool('phpcs', ['-s', '--standard=Jumbo', '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $this->assertSame(4, substr_count($result['output'], 'SpaceInEmptyArray'), $result['output']);
    }

    /**
     * Test that a line over 120 characters is a warning, not an error, in Jumbo too, and that the warning over 80
     * characters is hidden by default, and shown at severity 4.
     */
    public function testLineLengthLimitsAreWarnings(): void
    {
        $fixture = __DIR__ . '/../../PERCS31/Tests/Ruleset/LineLength.inc';
        $args = ['-s', '--standard=Jumbo', '--sniffs=Generic.Files.LineLength', '--report=emacs', $fixture];
        $default = $this->runTool('phpcs', $args);
        $severity4 = $this->runTool('phpcs', ['--warning-severity=4', ...$args]);

        $this->assertMatchesRegularExpression('/:16:\d+: warning - .*LineLength\.MaxExceeded/', $default['output']);
        $this->assertStringNotContainsString(' error - ', $default['output']);
        $this->assertStringNotContainsString('LineLength.TooLong', $default['output']);
        foreach ([9, 10, 11] as $line) {
            $pattern = "/:{$line}:\\d+: warning - .*LineLength\\.TooLong/";
            $this->assertMatchesRegularExpression($pattern, $severity4['output']);
        }

        $this->assertStringNotContainsString(':12:', $severity4['output'], 'An unbreakable comment is exempt.');
    }

    #endregion
}
