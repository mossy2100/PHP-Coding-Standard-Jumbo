<?php

declare(strict_types=1);

namespace Jumbo\Tests;

use PERCS31\Tests\RunsToolsTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the properties and codes the Jumbo ruleset sets on the sniffs it includes, which can only be set in a
 * ruleset.
 *
 * Like RulesetUnitTest, these run phpcs and phpcbf as a user would.
 */
#[CoversNothing]
class RulesetPropertiesUnitTest extends TestCase
{
    use RunsToolsTrait;

    /**
     * The fixture directory.
     */
    private const string FIXTURES = __DIR__ . '/Ruleset';

    /**
     * The option that turns off Squiz.Classes.ClassFileName, for a test that runs Jumbo on a temporary file, whose
     * name never matches the class in it.
     */
    private const string EXCLUDE_CLASS_FILE_NAME = '--exclude=Squiz.Classes.ClassFileName';

    #region Configurable property tests.

    /**
     * Test that ScopeIndent's indent property, set in a ruleset, changes the expected indent.
     *
     * ScopeIndent checks the whole file when it reaches the open tag, before any `phpcs:set` comment, so this
     * property can't be set in a sniff test's `.inc` file.
     */
    public function testScopeIndentIndentProperty(): void
    {
        $fixture = self::FIXTURES . '/ScopeIndentTwoSpaces.inc';
        $ruleset = $this->copyToTempFile(null, <<<'XML'
            <?xml version="1.0"?>
            <ruleset name="ScopeIndentTwoSpaces">
                <rule ref="PERCS31.WhiteSpace.ScopeIndent">
                    <properties>
                        <property name="indent" value="2"/>
                    </properties>
                </rule>
            </ruleset>
            XML, '.xml');

        try {
            $twoSpaces = $this->runTool('phpcs', ["--standard={$ruleset}", $fixture]);
            $default = $this->runTool(
                'phpcs',
                ['--standard=Jumbo', '--sniffs=PERCS31.WhiteSpace.ScopeIndent', $fixture],
            );
        } finally {
            unlink($ruleset);
        }

        $this->assertSame(0, $twoSpaces['exitCode'], $twoSpaces['output']);
        $this->assertNotSame(0, $default['exitCode']);
    }

    /**
     * Test that Jumbo reports an unused parameter, but not in a class that extends a class or implements an interface,
     * where a parent or the interface may fix the signature.
     */
    public function testUnusedParameterIsSkippedInExtendingClass(): void
    {
        $plain = $this->copyToTempFile(null, <<<'PHP'
            <?php

            final class Plain
            {
                public function handle(int $used, int $unused): int
                {
                    return $used;
                }
            }
            PHP);
        $extending = $this->copyToTempFile(null, <<<'PHP'
            <?php

            final class Extending extends Base
            {
                public function handle(int $used, int $unused): int
                {
                    return $used;
                }
            }
            PHP);
        $args = ['-s', '--standard=Jumbo', '--sniffs=Generic.CodeAnalysis.UnusedFunctionParameter', '--report=emacs'];

        try {
            $plainResult = $this->runTool('phpcs', [...$args, $plain]);
            $extendingResult = $this->runTool('phpcs', [...$args, $extending]);
        } finally {
            unlink($plain);
            unlink($extending);
        }

        $this->assertStringContainsString('UnusedFunctionParameter.FoundAfterLastUsed', $plainResult['output']);
        $this->assertSame(0, $extendingResult['exitCode'], $extendingResult['output']);
    }

    /**
     * Test that Jumbo allows an unused parameter before a used one, as it can't be dropped without moving the used one,
     * which a callback's caller may not allow.
     */
    public function testUnusedParameterBeforeUsedOneIsAllowed(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            $filtered = array_filter($items, static fn($value, $key) => $key > 0, ARRAY_FILTER_USE_BOTH);
            PHP);

        try {
            $result = $this->runTool(
                'phpcs',
                ['-s', '--standard=Jumbo', '--sniffs=Generic.CodeAnalysis.UnusedFunctionParameter', $file],
            );
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $result['exitCode'], $result['output']);
    }

    /**
     * Test that Jumbo requires self, not static, in a final class, except as a return type. On PHP 8.4, self can't
     * replace a static return type that a parent declares.
     */
    public function testStaticReturnTypeInFinalClassIsAllowed(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            abstract class Base
            {
                abstract public function make(): static;
            }

            final class Child extends Base
            {
                public function make(): static
                {
                    return new static();
                }
            }
            PHP);
        $sniffs = '--sniffs=Universal.CodeAnalysis.StaticInFinalClass';

        try {
            $result = $this->runTool('phpcs', ['-s', '--standard=Jumbo', $sniffs, '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $this->assertStringContainsString('StaticInFinalClass.NewInstance', $result['output']);
        $this->assertStringNotContainsString('StaticInFinalClass.ReturnType', $result['output']);
    }

    /**
     * Test that Jumbo reports a space inside grouping parentheses on one line, but not a line break inside them, which
     * PERCS31.Formatting.ExpressionFormat and PERCS31.Operators.OperatorPlacement check.
     */
    public function testLineBreakInGroupingParenthesesIsAllowed(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            $total = ( $a + $b ) * 2;
            $split = (
                $a
                + $b
            ) * 2;
            PHP);
        $sniffs = '--sniffs=Generic.WhiteSpace.ArbitraryParenthesesSpacing';

        try {
            $result = $this->runTool('phpcs', ['--standard=Jumbo', $sniffs, '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $this->assertSame(2, substr_count($result['output'], "{$file}:3:"), $result['output']);
        $this->assertStringNotContainsString("{$file}:4:", $result['output']);
        $this->assertStringNotContainsString("{$file}:7:", $result['output']);
    }

    /**
     * Test that Jumbo reports a run of spaces in code, but not aligned text in a comment or a doc comment.
     */
    public function testDuplicateSpacesInCommentsAreAllowed(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            // Aligned  text  in  a  comment.
            /**
             * @param int    $a  The value.
             */
            function double(int $a): int
            {
                return  $a * 2;
            }
            PHP);
        $sniffs = '--sniffs=SlevomatCodingStandard.Whitespaces.DuplicateSpaces';

        try {
            $result = $this->runTool('phpcs', ['--standard=Jumbo', $sniffs, '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $this->assertSame(1, substr_count($result['output'], 'Duplicate spaces'), $result['output']);
        $this->assertStringContainsString("{$file}:9:", $result['output']);
    }

    /**
     * Test that Jumbo allows a class of up to 1000 lines of code, where SlevomatCodingStandard.Classes.ClassLength's
     * default is 250.
     */
    public function testClassLengthLimitIs1000Lines(): void
    {
        $class = static fn(int $lines): string => "<?php\n\nfinal class Long\n{\n"
            . implode('', array_map(static fn(int $i): string => "    public int \$p{$i} = 0;\n", range(1, $lines)))
            . "}\n";
        $under = $this->copyToTempFile(null, $class(990));
        $over = $this->copyToTempFile(null, $class(1001));
        $args = ['-s', '--standard=Jumbo', '--sniffs=SlevomatCodingStandard.Classes.ClassLength', '--report=emacs'];

        try {
            $underResult = $this->runTool('phpcs', [...$args, $under]);
            $overResult = $this->runTool('phpcs', [...$args, $over]);
        } finally {
            unlink($under);
            unlink($over);
        }

        $this->assertSame(0, $underResult['exitCode'], $underResult['output']);
        $this->assertStringContainsString('ClassLength.ClassTooLong', $overResult['output']);
    }

    /**
     * Test that extract() is the only function Generic.PHP.ForbiddenFunctions forbids in Jumbo. Its default list of
     * aliases, such as sizeof(), is left to Jumbo.PHP.FunctionCalls.
     */
    public function testExtractIsTheOnlyForbiddenFunction(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            extract($values);
            $count = sizeof($values);
            PHP);
        $sniffs = '--sniffs=Generic.PHP.ForbiddenFunctions';

        try {
            $result = $this->runTool('phpcs', ['-s', '--standard=Jumbo', $sniffs, '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $this->assertSame(1, substr_count($result['output'], 'ForbiddenFunctions.Found'), $result['output']);
        $this->assertStringContainsString("{$file}:3:", $result['output']);
    }

    /**
     * Test that Jumbo warns for a closure over 10 lines of code, and reports an error for one over 20, where
     * Universal.FunctionDeclarations.NoLongClosures's defaults are 5 and 8.
     */
    public function testClosureLengthLimitsAre10And20Lines(): void
    {
        $closure = static fn(int $lines): string
            => "<?php\n\n\$run = static function (): void {\n" . str_repeat("    run();\n", $lines) . "};\n";
        $args = ['-s', '--standard=Jumbo', '--sniffs=Universal.FunctionDeclarations.NoLongClosures', '--report=emacs'];
        $results = [];

        foreach ([10, 11, 20, 21] as $lines) {
            $file = $this->copyToTempFile(null, $closure($lines));

            try {
                $results[$lines] = $this->runTool('phpcs', [...$args, $file])['output'];
            } finally {
                unlink($file);
            }
        }

        $this->assertStringNotContainsString('NoLongClosures', $results[10]);
        $this->assertStringContainsString('warning - ', $results[11]);
        $this->assertStringContainsString('warning - ', $results[20]);
        $this->assertStringContainsString('error - ', $results[21]);
    }

    /**
     * Test that an empty catch needs a comment saying why, from Squiz.Commenting.EmptyCatchComment, where any other
     * empty body is Generic.CodeAnalysis.EmptyStatement's.
     */
    public function testEmptyCatchNeedsAComment(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            try {
                run();
            } catch (Exception) {
            }

            try {
                run();
            } catch (Exception) {
                // Nothing to do: the run is optional.
            }

            if ($a) {
            }
            PHP);
        $sniffs = '--sniffs=Generic.CodeAnalysis.EmptyStatement,Squiz.Commenting.EmptyCatchComment';

        try {
            $result = $this->runTool('phpcs', ['-s', '--standard=Jumbo', $sniffs, '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $this->assertMatchesRegularExpression('/:5:\d+: error - .*EmptyCatchComment\.Missing/', $result['output']);
        $this->assertMatchesRegularExpression('/:14:\d+: error - .*EmptyStatement\.DetectedIf/', $result['output']);
        $this->assertStringNotContainsString('DetectedCatch', $result['output']);
        $this->assertStringNotContainsString(':10:', $result['output']);
    }

    /**
     * Test that Jumbo removes a @param, @return or @var that only repeats the native type, and keeps one with a
     * description or a more specific type.
     */
    public function testUselessAnnotationsAreRemoved(): void
    {
        $file = $this->copyToTempFile(null, <<<'PHP'
            <?php

            declare(strict_types=1);

            final class Example
            {
                /**
                 * Bare.
                 *
                 * @param int $a
                 * @param list<int> $b
                 * @return int
                 */
                public function bare(int $a, array $b): int
                {
                    return $a + count($b);
                }

                /**
                 * Described.
                 *
                 * @param int $a The value.
                 * @return int The doubled value.
                 */
                public function described(int $a): int
                {
                    return $a * 2;
                }
            }
            PHP);

        try {
            $this->runTool('phpcbf', ['--standard=Jumbo', self::EXCLUDE_CLASS_FILE_NAME, $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $this->assertStringContainsString("     * Bare.\n     *\n     * @param list<int> \$b\n     */", $fixed);
        $this->assertStringContainsString('@param int $a The value.', $fixed);
        $this->assertStringContainsString('@return int The doubled value.', $fixed);
    }

    #endregion
}
