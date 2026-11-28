<?php

declare(strict_types=1);

namespace Jumbo\Tests;

use PERCS31\Tests\RunsToolsTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests comparing the full PERCS31 and Jumbo rulesets: the rules each one has that the other doesn't, and where they
 * agree.
 *
 * Split from RulesetUnitTest, by region, as one class was too long. These run phpcs and phpcbf as a user would.
 */
#[CoversNothing]
class RulesetComparisonUnitTest extends TestCase
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

    #region Tests comparing PERCS31 and Jumbo.

    /**
     * Test that code following the Jumbo standard also follows PERCS31, which Jumbo builds on.
     */
    public function testGoodFixturePassesPercs31(): void
    {
        $result = $this->runTool('phpcs', ['--standard=PERCS31', '--report=json', self::FIXTURES . '/Good.inc']);

        $this->assertSame(0, $result['exitCode'], $result['output']);
    }

    /**
     * Test that code following PER Coding Style, but not the extra rules, passes PERCS31 and fails Jumbo.
     */
    public function testExtraRulesFixturePassesPercs31Only(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Jumbo/Tests/Ruleset/ExtraRules.inc';
        $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', '--report=json', $fixture]);
        $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', '--report=json', $fixture]);

        $this->assertSame(0, $percs31['exitCode'], $percs31['output']);
        $this->assertNotSame(0, $jumbo['exitCode']);
    }

    /**
     * Test that PERCS31 allows more than one space around an operator, as PER does, and Jumbo doesn't.
     */
    public function testOperatorSpacingAllowsSeveralSpacesInPercs31Only(): void
    {
        $file = $this->copyToTempFile(null, "<?php\n\n\$total = \$price  +  \$tax;\n\$valid = \$a  &&  \$b;\n");
        $args = ['-s', '--sniffs=PERCS31.Operators.OperatorSpacing', '--report=emacs', $file];

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', ...$args]);
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', ...$args]);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $percs31['exitCode'], $percs31['output']);
        $this->assertSame(4, substr_count($jumbo['output'], 'PERCS31.Operators.OperatorSpacing.Spacing'));
    }

    /**
     * Test that PERCS31 only reports whitespace after a reference `&` and a spread `...`, as PER leaves the other unary
     * operators undefined, and Jumbo reports it after `+`, `-`, `!`, `~` and `@` too. A spread is reported by
     * Generic.WhiteSpace.SpreadOperatorSpacingAfter. A variadic parameter's `...` is reported once, and a first-class
     * callable's isn't a spread.
     */
    public function testUnaryOperatorSpacingChecksOnlyReferenceAndSpreadInPercs31(): void
    {
        $source = <<<'TEXT'
            <?php

            $a = & $b;
            $c = + $b;
            $d = - $b;
            $e = ! $b;
            $f = ~ $b;
            $g = @ foo();
            $h = foo(... $b);
            $i = fn(int ... $j): array => $j;
            $k = foo(...);

            TEXT;
        $file = $this->copyToTempFile(null, $source);
        $sniffs = 'PERCS31.Operators.UnaryOperatorSpacing,Generic.WhiteSpace.SpreadOperatorSpacingAfter,Squiz.Functions'
            . '.FunctionDeclarationArgumentSpacing';
        $args = ['-s', "--sniffs={$sniffs}", '--report=emacs', $file];

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', ...$args]);
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', ...$args]);
        } finally {
            unlink($file);
        }

        $code = 'PERCS31.Operators.UnaryOperatorSpacing.WhitespaceAfter';

        $spreadCode = 'Generic.WhiteSpace.SpreadOperatorSpacingAfter.TooMuchSpace';

        $this->assertSame(1, substr_count($percs31['output'], $code), $percs31['output']);
        $this->assertSame(2, substr_count($percs31['output'], $spreadCode), $percs31['output']);
        $this->assertStringContainsString(':3:', $percs31['output']);
        $this->assertStringContainsString(':9:', $percs31['output']);
        $this->assertStringContainsString(':10:', $percs31['output']);
        $this->assertStringNotContainsString('SpacingAfterVariadic', $percs31['output']);
        $this->assertSame(6, substr_count($jumbo['output'], $code), $jumbo['output']);
        $this->assertSame(2, substr_count($jumbo['output'], $spreadCode), $jumbo['output']);
        $this->assertStringNotContainsString('SpacingAfterVariadic', $jumbo['output']);
    }

    /**
     * Test that a foreach or match header split over lines takes the split layout in Jumbo, with a foreach's `as`
     * starting a line, and that PERCS31 leaves both alone, as PER has no rule for either. An array that starts the
     * header goes on a line of its own, and phpcbf gets there without the array sniff moving it back.
     */
    public function testSplitForeachAndMatchHeadersTakeTheSplitLayoutInJumboOnly(): void
    {
        $item = "'" . str_repeat('alpha ', 9) . "alpha'";
        $source = <<<TEXT
            <?php

            declare(strict_types=1);

            foreach ([
                {$item},
                {$item},
            ] as \$key => \$item) {
                run();
            }

            \$result = match (\$this->lookup([
                {$item},
                {$item},
            ])) {
                1 => 'one',
                default => 'other',
            };

            TEXT;
        $expected = <<<TEXT
            <?php

            declare(strict_types=1);

            foreach (
                [
                    {$item},
                    {$item},
                ]
                as \$key => \$item
            ) {
                run();
            }

            \$result = match (
                \$this->lookup([
                    {$item},
                    {$item},
                ])
            ) {
                1 => 'one',
                default => 'other',
            };

            TEXT;
        $file = $this->copyToTempFile(null, $source);
        $ignored = '--exclude=PSR1.Files.SideEffects';

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', $ignored, '--report=emacs', $file]);
            $this->runTool('phpcbf', ['--standard=Jumbo', $ignored, $file]);
            $fixed = (string) file_get_contents($file);
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', $ignored, '--report=emacs', $file]);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $percs31['exitCode'], $percs31['output']);
        $this->assertSame($expected, $fixed);
        $this->assertSame(0, $jumbo['exitCode'], $jumbo['output']);
    }

    /**
     * Test that Jumbo allows no blank line at the start of a function's, method's or closure's body, or at either end
     * of a block declare, a property's hook list or a hook's body, and that PERCS31 allows them all, as PER has no rule
     * for any of them.
     */
    public function testBlankLinesAtTheEndsOfABlockAreReportedInJumboOnly(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Package;\n\n";
        $source = <<<TEXT
            {$header}class Example
            {
                public string \$name {

                    get {

                        return \$this->name;

                    }

                }

                public function run(): int
                {

                    \$double = static function (int \$x): int {

                        return \$x * 2;
                    };

                    return \$double(1);
                }
            }

            TEXT;
        $expected = <<<TEXT
            {$header}class Example
            {
                public string \$name {
                    get {
                        return \$this->name;
                    }
                }

                public function run(): int
                {
                    \$double = static function (int \$x): int {
                        return \$x * 2;
                    };

                    return \$double(1);
                }
            }

            TEXT;
        $declare = "<?php\n\ndeclare(strict_types=1);\ndeclare(ticks=1) {\n\n    run();\n\n}\n";
        $file = $this->copyToTempFile(null, $source);
        $declareFile = $this->copyToTempFile(null, $declare);
        // The closure stays a closure, so its body's blank lines can be checked.
        $ignored = '--exclude=PSR1.Files.SideEffects,Jumbo.Functions.RequireArrowFunction';

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', $ignored, '--report=emacs', $file, $declareFile]);
            $jumbo = $this->runTool('phpcs', ['-s', '--standard=Jumbo', $ignored, '--report=emacs', $file]);
            $this->runTool('phpcbf', ['--standard=Jumbo', $ignored, $file, $declareFile]);
            $fixed = (string) file_get_contents($file);
            $fixedDeclare = (string) file_get_contents($declareFile);
        } finally {
            unlink($file);
            unlink($declareFile);
        }

        $this->assertSame(0, $percs31['exitCode'], $percs31['output']);
        $this->assertSame(2, substr_count($jumbo['output'], 'FunctionOpeningBraceSpace.SpacingAfter'));
        $this->assertSame(2, substr_count($jumbo['output'], 'BlockBlankLines.AfterOpeningBrace'), $jumbo['output']);
        $this->assertSame(2, substr_count($jumbo['output'], 'BlockBlankLines.BeforeClosingBrace'), $jumbo['output']);
        $this->assertSame($expected, $fixed);
        $this->assertSame(str_replace(["{\n\n", "\n\n}"], ["{\n", "\n}"], $declare), $fixedDeclare);
    }

    /**
     * Test that Jumbo allows no more than one blank line in a row, between statements and between a list's items, and
     * PERCS31 any number. A blank line in a nowdoc or a block comment is part of it, so it's left alone.
     */
    public function testConsecutiveBlankLinesAreReportedInJumboOnly(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Package;\n\n";
        $kept = "\$text = <<<'EOT'\n    one\n\n\n    two\n    EOT;\n\n/*\n * One.\n\n\n * Two.\n */\n";
        $argument = "    'an argument long enough that the call cannot be joined onto a single line by Jumbo',\n";
        $call = "send(\n    \$text,\n%s{$argument}{$argument});\n";
        $file = $this->copyToTempFile(null, "{$header}{$kept}\n\n" . sprintf($call, "\n\n\n"));
        $ignored = '--exclude=PSR1.Files.SideEffects';

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', $ignored, '--report=emacs', $file]);
            $jumbo = $this->runTool('phpcs', ['-s', '--standard=Jumbo', $ignored, '--report=emacs', $file]);
            $this->runTool('phpcbf', ['--standard=Jumbo', $ignored, $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $reported = substr_count($jumbo['output'], 'PhpCollective.WhiteSpace.EmptyLines');

        $this->assertSame(0, $percs31['exitCode'], $percs31['output']);
        $this->assertSame(3, $reported, $jumbo['output']);
        $this->assertSame("{$header}{$kept}\n" . sprintf($call, "\n"), $fixed);
    }

    /**
     * Test that a closing tag at the end of a file mixing PHP and HTML is an error in Jumbo only, as PER only requires
     * omitting it from a file containing only PHP.
     */
    public function testClosingTagInMixedFileIsAnErrorInJumboOnly(): void
    {
        $file = $this->copyToTempFile(null, "<?php\n\n\$a = 1;\n\n?>\n<p>Hi</p>\n<?php\n\n\$b = 2;\n\n?>\n");
        $args = ['-s', '--sniffs=PSR2.Files.ClosingTag,Zend.Files.ClosingTag', '--report=emacs', $file];

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', ...$args]);
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', ...$args]);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $percs31['exitCode'], $percs31['output']);
        $this->assertStringContainsString('error - A closing tag', $jumbo['output']);
        $this->assertStringContainsString('Zend.Files.ClosingTag.NotAllowed', $jumbo['output']);
    }

    /**
     * Test that a closing tag at the end of a PHP-only file is reported once in each standard, as Jumbo excludes
     * PSR2.Files.ClosingTag in favour of Zend.Files.ClosingTag, which would otherwise report it a second time.
     */
    public function testClosingTagInPhpOnlyFileIsReportedOnce(): void
    {
        $file = $this->copyToTempFile(null, "<?php\n\n\$a = 1;\n\n?>\n");
        $args = ['-s', '--sniffs=PSR2.Files.ClosingTag,Zend.Files.ClosingTag', '--report=emacs', $file];

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', ...$args]);
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', ...$args]);
        } finally {
            unlink($file);
        }

        $this->assertSame(1, substr_count($percs31['output'], 'PSR2.Files.ClosingTag.NotAllowed'));
        $this->assertStringNotContainsString('Zend.Files.ClosingTag', $percs31['output']);
        $this->assertSame(1, substr_count($jumbo['output'], 'Zend.Files.ClosingTag.NotAllowed'));
        $this->assertStringNotContainsString('PSR2.Files.ClosingTag', $jumbo['output']);
    }

    /**
     * Test that a control structure's empty body with the braces on separate lines passes in PERCS31, and is an error
     * in Jumbo, whose fix writes it as `{}`.
     */
    public function testEmptyControlStructureBodyMustBeAbbreviatedInJumbo(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Package;\n\n";
        $file = $this->copyToTempFile(null, "{$header}while (next(\$items)) {\n}\n");
        $sniff = '--sniffs=PERCS31.Formatting.EmptyBody';

        try {
            $per = $this->runTool('phpcs', ['--standard=PERCS31', $sniff, '-s', '--report=emacs', $file])['output'];
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', $sniff, '-s', '--report=emacs', $file])['output'];
            $this->runTool('phpcbf', ['--standard=Jumbo', $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $this->assertStringNotContainsString('EmptyBody', $per);
        $this->assertStringContainsString('error - An empty while body must be written as {}', $jumbo);
        $this->assertSame("{$header}while (next(\$items)) {}\n", $fixed);
    }

    /**
     * Test that a blank line after an anonymous class's opening brace passes in PERCS31, which gives it the closure
     * rules, and is an error in Jumbo. One after a named class's brace is an error in both.
     */
    public function testBlankLineAfterAnonClassBraceIsReportedInJumboOnly(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Package;\n\n";
        $anon = "\$a = new class {\n\n    public int \$b = 1;\n};\n";
        $named = "final class Named\n{\n\n    public int \$b = 1;\n}\n";
        $file = $this->copyToTempFile(null, "{$header}{$anon}\n{$named}");

        try {
            $per = $this->runTool('phpcs', ['--standard=PERCS31', '-s', '--report=emacs', $file])['output'];
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', '-s', '--report=emacs', $file])['output'];
        } finally {
            unlink($file);
        }

        $this->assertStringNotContainsString(':7:', $per);
        $this->assertStringContainsString(':13:1: error', $per);
        $this->assertStringContainsString(':7:16: error', $jumbo);
    }

    /**
     * Test that a blank line before a split control structure header's closing parenthesis passes in PERCS31, and is
     * an error in Jumbo, whose fix removes it.
     */
    public function testBlankLineBeforeCloseParenthesisIsReportedInJumboOnly(): void
    {
        $source = "<?php\n\nwhile (\n    next(\$items)\n\n) {\n    run();\n}\n";
        $file = $this->copyToTempFile(null, $source);
        $sniff = '--sniffs=PERCS31.ControlStructures.ControlStructureSpacing';

        try {
            $per = $this->runTool('phpcs', ['--standard=PERCS31', $sniff, '-s', '--report=emacs', $file])['output'];
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', $sniff, '-s', '--report=emacs', $file])['output'];
            $this->runTool('phpcbf', ['--standard=Jumbo', $sniff, $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $this->assertStringNotContainsString('ControlStructureSpacing', $per);
        $this->assertStringContainsString('ControlStructureSpacing.BlankLineBeforeCloseParenthesis', $jumbo);
        $this->assertSame("<?php\n\nwhile (\n    next(\$items)\n) {\n    run();\n}\n", $fixed);
    }

    /**
     * Test that a comment indented too far after a statement passes in PERCS31, which has no rule for a comment's
     * indent, and is an error in Jumbo.
     */
    public function testOverIndentedCommentIsReportedInJumboOnly(): void
    {
        $source = "<?php\n\nfunction total(): int\n{\n    \$a = 1;\n        // Too far.\n    return \$a;\n}\n";
        $file = $this->copyToTempFile(null, $source);
        $sniff = '--sniffs=PERCS31.WhiteSpace.ConsistentIndent';

        try {
            $per = $this->runTool('phpcs', ['--standard=PERCS31', $sniff, '-s', '--report=emacs', $file])['output'];
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', $sniff, '-s', '--report=emacs', $file])['output'];
        } finally {
            unlink($file);
        }

        $this->assertStringNotContainsString('ConsistentIndent', $per);
        $this->assertStringContainsString('PERCS31.WhiteSpace.ConsistentIndent.Comment', $jumbo);
    }

    /**
     * Test the blank lines around trait use statements in Jumbo: none between two, exactly one after the last, each
     * reported once, and fixed. PERCS31 allows one between two, and several after the last. A comment at the end of
     * the last use statement's line, before the class's closing brace, passes and is kept, which
     * SlevomatCodingStandard.Classes.TraitUseSpacing's fix deleted.
     */
    public function testBlankLinesAroundTraitUseAreExactInJumbo(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Package;\n\n";
        $source = "{$header}class Between\n{\n    use First;\n\n    use Second;\n\n\n    public int \$a = 1;\n}\n";
        $file = $this->copyToTempFile(null, $source);
        $last = $this->copyToTempFile(null, "{$header}class Last\n{\n    use First;\n    public int \$a = 1;\n}\n");
        $commented = "{$header}class Commented\n{\n    use First; // The first.\n}\n";
        $commentedFile = $this->copyToTempFile(null, $commented);
        $args = ['-s', '--standard=Jumbo', '--report=emacs', self::EXCLUDE_CLASS_FILE_NAME];

        try {
            $result = $this->runTool('phpcs', [...$args, $file]);
            $lastResult = $this->runTool('phpcs', [...$args, $last]);
            $commentedResult = $this->runTool('phpcs', [...$args, $commentedFile]);
            $this->runTool('phpcbf', ['--standard=Jumbo', $file, $commentedFile]);
            $fixed = (string) file_get_contents($file);
            $fixedCommented = (string) file_get_contents($commentedFile);
        } finally {
            unlink($file);
            unlink($last);
            unlink($commentedFile);
        }

        $this->assertSame(1, substr_count($result['output'], 'TraitUseBlock.BlankLineBetweenUses'), $result['output']);
        $this->assertSame(1, substr_count($result['output'], 'WhiteSpace.EmptyLines.EmptyLines'), $result['output']);
        $this->assertSame(1, substr_count($lastResult['output'], 'TraitUseBlock.'), $lastResult['output']);
        $this->assertStringContainsString('TraitUseBlock.NoBlankLineAfterUse', $lastResult['output']);
        $this->assertStringNotContainsString('PSR12.Traits.UseDeclaration', $lastResult['output']);
        $this->assertSame(0, $commentedResult['exitCode'], $commentedResult['output']);
        $expected = "{$header}class Between\n{\n    use First;\n    use Second;\n\n    public int \$a = 1;\n}\n";
        $this->assertSame($expected, $fixed);
        $this->assertSame($commented, $fixedCommented);
    }

    /**
     * Test that a global const statement with two constants, or two spaces after const, is reported and fixed in
     * Jumbo, and passes in PERCS31, as section 4 is about classes. A class's constants are reported in both.
     */
    public function testGlobalConstantsPerStatementInJumboOnly(): void
    {
        $source
            = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Package;\n\nconst A = 1, B = 2;\nconst  C = 3;\n";
        $file = $this->copyToTempFile(null, $source);
        $args = ['-s', '--sniffs=PERCS31.Classes.ConstantDeclaration', '--report=emacs'];

        try {
            $percs31 = $this->runTool('phpcs', ['--standard=PERCS31', ...$args, $file]);
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', ...$args, $file]);
            $this->runTool('phpcbf', ['--standard=Jumbo', '--sniffs=PERCS31.Classes.ConstantDeclaration', $file]);
            $fixed = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $percs31['exitCode'], $percs31['output']);
        $this->assertStringContainsString('ConstantDeclaration.DisallowedMultiConstantDefinition', $jumbo['output']);
        $this->assertStringContainsString('ConstantDeclaration.SpaceAfterConst', $jumbo['output']);
        $this->assertStringContainsString("const A = 1;\nconst B = 2;\nconst C = 3;\n", $fixed);
    }

    /**
     * Test that a blank line after a case label is reported in Jumbo only, as PER doesn't forbid one, that a statement
     * on the label's line is reported once in both standards, and that a comment there isn't reported in either.
     */
    public function testBlankLineAfterLabelIsReportedInJumboOnly(): void
    {
        $blank = $this->copyToTempFile(null, "<?php\n\nswitch (\$a) {\n    case 1:\n\n        break;\n}\n");
        $comment = $this->copyToTempFile(null, "<?php\n\nswitch (\$a) {\n    case 1: // Why.\n        break;\n}\n");
        $statement = $this->copyToTempFile(null, "<?php\n\nswitch (\$a) {\n    case 1: break;\n}\n");
        $sniff = '--sniffs=PERCS31.ControlStructures.SwitchDeclaration';

        try {
            $perBlank = $this->runTool('phpcs', ['--standard=PERCS31', $sniff, '-s', $blank])['output'];
            $jumboBlank = $this->runTool('phpcs', ['--standard=Jumbo', $sniff, '-s', $blank])['output'];
            $perComment = $this->runTool('phpcs', ['--standard=PERCS31', $sniff, '-s', $comment])['output'];
            $jumboComment = $this->runTool('phpcs', ['--standard=Jumbo', $sniff, '-s', $comment])['output'];
            $perStatement = $this->runTool('phpcs', ['--standard=PERCS31', $sniff, '-s', $statement])['output'];
            $jumboStatement = $this->runTool('phpcs', ['--standard=Jumbo', $sniff, '-s', $statement])['output'];
        } finally {
            unlink($blank);
            unlink($comment);
            unlink($statement);
        }

        $this->assertStringNotContainsString('SwitchDeclaration', $perBlank);
        $this->assertStringContainsString('SwitchDeclaration.BodyOnNextLineCASE', $jumboBlank);
        $this->assertStringNotContainsString('SwitchDeclaration', $perComment);
        $this->assertStringNotContainsString('SwitchDeclaration', $jumboComment);
        $this->assertSame(1, substr_count($perStatement, 'SwitchDeclaration.'), $perStatement);
        $this->assertStringContainsString('SwitchDeclaration.StatementOnLabelLine', $perStatement);
        $this->assertSame(1, substr_count($jumboStatement, 'SwitchDeclaration.'), $jumboStatement);
        $this->assertStringContainsString('SwitchDeclaration.BodyOnNextLineCASE', $jumboStatement);
    }

    /**
     * Test that a statement after a block declare in the header is one spacing error in both standards, as section 3
     * wants a blank line, and that one after any other control structure is an error in both too.
     */
    public function testStatementAfterClosingBrace(): void
    {
        $block = "<?php\n\ndeclare(strict_types=1);\ndeclare(ticks=1) {\n    echo 1;\n} echo 2;\n";
        $structure = "<?php\n\ndeclare(strict_types=1);\n\nif (\$a) {\n    echo 1;\n} echo 2;\n";
        foreach ([$block, $structure] as $source) {
            $file = $this->copyToTempFile(null, $source);

            try {
                $perCs = $this->runTool('phpcs', ['-s', '--standard=PERCS31', '--report=emacs', $file]);
                $jumbo = $this->runTool('phpcs', ['-s', '--standard=Jumbo', '--report=emacs', $file]);
            } finally {
                unlink($file);
            }

            $isBlock = $source === $block;
            $this->assertSame($isBlock, str_contains($perCs['output'], 'SpacingAfterDeclareBlock'), $perCs['output']);
            $this->assertSame($isBlock, str_contains($jumbo['output'], 'SpacingAfterDeclareBlock'), $jumbo['output']);
            $this->assertSame(!$isBlock, str_contains($perCs['output'], 'StatementAfter'), $perCs['output']);
            $this->assertSame(!$isBlock, str_contains($jumbo['output'], 'StatementAfter'), $jumbo['output']);
        }
    }

    /**
     * Test that a `match` that's a call's only argument is hugged, whether written chopped down or on one line, and
     * that phpcbf finishes.
     */
    public function testOnlyMatchArgumentIsHugged(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace A;\n\n";
        $chopped = "\$d = foo(\n    match (\$x) {\n        1 => 2,\n        default => 3,\n    },\n);\n";
        $hugged = "\$d = foo(match (\$x) {\n    1 => 2,\n    default => 3,\n});\n";
        foreach ([$chopped, "\$d = foo(match (\$x) { 1 => 2, default => 3 });\n"] as $code) {
            $file = $this->copyToTempFile(null, "{$header}{$code}");

            try {
                $fixer = $this->runTool('phpcbf', ['--standard=Jumbo', $file]);
                $fixed = (string) file_get_contents($file);
            } finally {
                unlink($file);
            }

            $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
            $this->assertSame("{$header}{$hugged}", $fixed);
        }
    }

    /**
     * Test that a match arm split before its value is brought onto one line if it fits, in Jumbo. PERCS31 leaves it
     * split, and adds the trailing comma a split list of conditions needs.
     */
    public function testWrappedMatchArmIsJoinedInJumboOnly(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace A;\n\n\$a = match (\$x) {\n";
        $footer = "    default => 9,\n};\n";
        $expectations = ['Jumbo' => "    1, 2 => 3,\n", 'PERCS31' => "    1,\n    2,\n    => 3,\n"];
        foreach ($expectations as $standard => $expected) {
            $file = $this->copyToTempFile(null, "{$header}    1,\n    2\n    => 3,\n{$footer}");

            try {
                $fixer = $this->runTool('phpcbf', ["--standard={$standard}", $file]);
                $fixed = (string) file_get_contents($file);
            } finally {
                unlink($file);
            }

            $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
            $this->assertSame("{$header}{$expected}{$footer}", $fixed);
        }
    }

    /**
     * Test that commas between a match arm's conditions are spaced as in section 5.2's example, in both standards.
     */
    public function testMatchConditionCommaSpacing(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace A;\n\n\$a = match (\$x) {\n";
        $footer = "    default => 9,\n};\n";
        $arms = "    1,2 => 3,\n    4 , 5 => 6,\n";
        foreach (['PERCS31', 'Jumbo'] as $standard) {
            $file = $this->copyToTempFile(null, "{$header}{$arms}{$footer}");

            try {
                $result = $this->runTool('phpcs', ['-s', "--standard={$standard}", '--report=emacs', $file]);
                $fixer = $this->runTool('phpcbf', ["--standard={$standard}", $file]);
                $fixed = (string) file_get_contents($file);
            } finally {
                unlink($file);
            }

            $pattern = '/:(\\d+):\\d+: error - .*Universal\\.WhiteSpace\\.CommaSpacing\\.(\\w+)\\)/';
            preg_match_all($pattern, $result['output'], $matches, PREG_SET_ORDER);
            $found = array_map(static fn(array $match): string => "{$match[1]}:{$match[2]}", $matches);
            $this->assertSame(['8:NoSpaceAfter', '9:SpaceBefore'], $found, $result['output']);
            $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
            $this->assertSame("{$header}    1, 2 => 3,\n    4, 5 => 6,\n{$footer}", $fixed);
        }
    }

    /**
     * Test that `final` must come before a constant's visibility in both standards, and that the fix reorders them.
     */
    public function testConstantModifierOrderIsFixedInBothStandards(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace A;\n\nclass B\n{\n";
        $footer = "}\n";
        $code = "    public final const int C = 1;\n";
        $expected = "    final public const int C = 1;\n";
        foreach (['Jumbo', 'PERCS31'] as $standard) {
            $file = $this->copyToTempFile(null, "{$header}{$code}{$footer}");

            try {
                $result = $this->runTool('phpcs', ['-s', "--standard={$standard}", '--report=emacs', $file]);
                $fixer = $this->runTool('phpcbf', ["--standard={$standard}", $file]);
                $fixed = (string) file_get_contents($file);
            } finally {
                unlink($file);
            }

            $errorCode = 'Universal.Constants.ModifierKeywordOrder.Incorrect';
            $this->assertStringContainsString($errorCode, $result['output']);
            $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
            $this->assertSame("{$header}{$expected}{$footer}", $fixed);
        }
    }

    /**
     * Test that a default value's `=` can be aligned in PERCS31, which only requires a space, and not in Jumbo.
     */
    public function testDefaultParameterSpacing(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Jumbo/Tests/Ruleset/DefaultParameterSpacing.inc';
        $expected = [
            'PERCS31' => ['7:NoSpaceBefore', '7:NoSpaceAfter'],
            'Jumbo' => [
                '7:SpaceBeforeEquals',
                '7:SpaceAfterEquals',
                '13:SpaceBeforeEquals',
                '14:SpaceAfterEquals',
                '19:SpaceBeforeEquals',
            ],
        ];
        foreach ($expected as $standard => $codes) {
            $result = $this->runTool('phpcs', ['-s', "--standard={$standard}", '--report=emacs', $fixture]);

            $pattern = '/:(\\d+):\\d+: error - .*\\.(NoSpace(?:Before|After)|Space(?:Before|After)Equals)\\)/';
            preg_match_all($pattern, $result['output'], $matches, PREG_SET_ORDER);
            $found = array_map(static fn(array $match): string => "{$match[1]}:{$match[2]}", $matches);
            $this->assertSame($codes, $found, $result['output']);
        }
    }

    /**
     * Test that a hook's own opening brace is moved to one space after its name, in both standards.
     */
    public function testHookBraceSpacingIsFixed(): void
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace A;\n\nfinal class B\n{\n    public int \$c {\n";
        $body = "            return 1;\n        }\n    }\n}\n";
        foreach (['Jumbo' => "        get {\n", 'PERCS31' => "        get {\n"] as $standard => $expected) {
            $file = $this->copyToTempFile(null, "{$header}        get\n        {\n{$body}");

            try {
                $fixer = $this->runTool('phpcbf', ["--standard={$standard}", $file]);
                $fixed = (string) file_get_contents($file);
            } finally {
                unlink($file);
            }

            $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
            $this->assertSame("{$header}{$expected}{$body}", $fixed);
        }
    }

    /**
     * Test that a class member on a file's last line doesn't crash the run, so another file's report isn't lost.
     */
    public function testMemberOnLastLineDoesNotCrashTheRun(): void
    {
        $members = [
            'class A { public int $a = 1;',
            "class A { public int \$a {\n    get => 1;\n}",
            'class A { const B = 1;',
            'enum A { case B;',
        ];
        foreach ($members as $code) {
            $file = $this->copyToTempFile(null, "<?php\n\n{$code} }\n");

            try {
                $result = $this->runTool('phpcs', ['--standard=Jumbo', '--report=emacs', $file]);
                $fixer = $this->runTool('phpcbf', ['--standard=Jumbo', $file]);
            } finally {
                unlink($file);
            }

            $this->assertNotSame(255, $result['exitCode'], $result['output']);
            $this->assertStringNotContainsString('FAILED TO FIX', $fixer['output']);
            $this->assertNotSame(255, $fixer['exitCode'], $fixer['output']);
        }
    }

    /**
     * Test that both standards allow a blank line between two parameters or two `use` variables, as section 2.3 does,
     * and report one after an attribute or after an opening parenthesis. One before a closing parenthesis is reported
     * in Jumbo only, as PER doesn't forbid it. The parent's EmptyLine, which reports them all, is excluded in the
     * ruleset.
     */
    public function testBlankLinesInDeclaration(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Jumbo/Tests/Ruleset/BlankLinesInDeclaration.inc';
        $sniff = '--sniffs=PERCS31.Functions.MultiLineFunctionDeclaration';
        foreach (['PERCS31', 'Jumbo'] as $standard) {
            $result = $this->runTool('phpcs', ['-s', "--standard={$standard}", $sniff, '--report=emacs', $fixture]);

            preg_match_all('/:(\\d+):\\d+: error - .*\\.BlankLine\\)/', $result['output'], $matches);
            preg_match_all('/:(\\d+):\\d+: error - .*\\.BlankLineBeforeCloseBracket\\)/', $result['output'], $atEnd);
            $this->assertSame(['20', '47'], $matches[1], $result['output']);
            $this->assertSame($standard === 'Jumbo' ? ['41'] : [], $atEnd[1], $result['output']);
            $this->assertStringNotContainsString('EmptyLine', $result['output']);
        }
    }

    /**
     * Test that both standards allow a blank line between two of a call's arguments, as section 2.3 does, and report
     * one after its opening parenthesis, as BlankLine. One before its closing parenthesis is reported in Jumbo only, as
     * PER doesn't forbid it. The parent's EmptyLine, which reports them all, is excluded in the ruleset.
     */
    public function testBlankLinesInCall(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Jumbo/Tests/Ruleset/BlankLinesInCall.inc';
        $sniff = '--sniffs=PERCS31.Functions.FunctionCallLayout';
        foreach (['PERCS31', 'Jumbo'] as $standard) {
            $result = $this->runTool('phpcs', ['-s', "--standard={$standard}", $sniff, '--report=emacs', $fixture]);

            preg_match_all('/:(\\d+):\\d+: error - .*\\.BlankLine\\)/', $result['output'], $matches);
            preg_match_all('/:(\\d+):\\d+: error - .*\\.BlankLineBeforeCloseBracket\\)/', $result['output'], $atEnd);
            $this->assertSame(['25'], $matches[1], $result['output']);
            $this->assertSame($standard === 'Jumbo' ? ['15'] : [], $atEnd[1], $result['output']);
            $this->assertStringNotContainsString('EmptyLine', $result['output']);
        }
    }

    /**
     * Test that Jumbo hides an UPPER_CASE enum constant's warning by default, and shows it at severity 4.
     */
    public function testEnumConstantCaseWarningNeedsWarningSeverity4InJumbo(): void
    {
        $fixture = dirname(__DIR__, 2) . '/PERCS31/Tests/NamingConventions/ValidConstantNameUnitTest.1.inc';
        $args = ['-s', '--standard=Jumbo', '--sniffs=PERCS31.NamingConventions.ValidConstantName', $fixture];
        $default = $this->runTool('phpcs', ['--report=emacs', ...$args]);
        $severity4 = $this->runTool('phpcs', ['--report=emacs', '--warning-severity=4', ...$args]);

        $this->assertStringNotContainsString('EnumConstantNotPascalCase', $default['output']);
        $this->assertMatchesRegularExpression(
            '/:21:\\d+: warning - .*EnumConstantNotPascalCase/',
            $severity4['output'],
        );
    }

    /**
     * Test that Jumbo shows an acronym's warning by default, still as a warning.
     */
    public function testAcronymWarningIsShownInJumbo(): void
    {
        $file = $this->copyToTempFile(null, "<?php\n\n\$userID = 1;\n");

        try {
            $result = $this->runTool(
                'phpcs',
                ['-s', '--standard=Jumbo', '--sniffs=PERCS31.NamingConventions.AcronymCasing', '--report=emacs', $file],
            );
        } finally {
            unlink($file);
        }

        $this->assertMatchesRegularExpression('/:3:\\d+: warning - .*AcronymNotLikeWord/', $result['output']);
    }

    /**
     * Test the naming rules, in both standards: an acronym is a warning unless the name is excluded, and a name with
     * no lower-case letter isn't in PascalCase.
     */
    #[DataProvider('standardProvider')]
    public function testNamingRules(string $standard): void
    {
        $source = <<<'PHP'
            <?php

            // phpcs:set PERCS31.NamingConventions.AcronymCasing excludedNames HTTPClient,getHTTP
            class HTTPClient {}

            enum foo {}

            enum HTTPStatus {}

            enum XMLStatus
            {
                case HTTP;
                case Hearts;
                const HTTPOk = 1;
                const OK = 2;
            }

            use Foo\Bar as XMLBar;

            function getHTTP(): void {}

            function getXML(): void {}

            PHP;
        $file = $this->copyToTempFile(null, $source);

        try {
            $args = ['-s', "--standard={$standard}", '--warning-severity=4', '--report=emacs', $file];
            $result = $this->runTool('phpcs', $args);
        } finally {
            unlink($file);
        }

        $pattern = '/:(\d+):\d+: (\w+) - .*\.(NotPascalCase|AcronymNotLikeWord|EnumConstant\w+)\)$/m';
        preg_match_all($pattern, $result['output'], $matches, PREG_SET_ORDER);
        $reports = array_map(static fn(array $match): string => "{$match[1]} {$match[2]} {$match[3]}", $matches);
        $expected = [
            '6 error NotPascalCase',
            '8 warning AcronymNotLikeWord',
            '10 warning AcronymNotLikeWord',
            '12 warning AcronymNotLikeWord',
            '12 error NotPascalCase',
            '14 warning AcronymNotLikeWord',
            '15 warning EnumConstantNotPascalCase',
            '18 warning AcronymNotLikeWord',
            '22 warning AcronymNotLikeWord',
        ];

        $this->assertSame($expected, $reports, $result['output']);
    }

    /**
     * Test that global constants must be in UPPER_CASE in Jumbo only, and that an acronym is only reported in a name
     * with a lower-case letter.
     */
    public function testGlobalConstantCaseIsCheckedInJumboOnly(): void
    {
        $source = <<<'TEXT'
            <?php

            const userId = 1;

            define('userId', 2);

            const HTTP_OK = 3;

            const userID = 4;

            use const Foo\BAR as userId;

            TEXT;
        $file = $this->copyToTempFile(null, $source);
        $reports = [];

        try {
            foreach (['PERCS31', 'Jumbo'] as $standard) {
                $args = ['-s', "--standard={$standard}", '--report=emacs', $file];
                $output = $this->runTool('phpcs', $args)['output'];
                $pattern = '/:(\\d+):\\d+: \\w+ - .*\\.(ConstantNotUpperCase|AcronymNotLikeWord)\\)$/m';
                preg_match_all($pattern, $output, $matches, PREG_SET_ORDER);
                $reports[$standard] = array_map(static fn(array $match): string => "{$match[1]} {$match[2]}", $matches);
            }
        } finally {
            unlink($file);
        }

        $jumbo = [
            '3 ConstantNotUpperCase',
            '5 ConstantNotUpperCase',
            '9 ConstantNotUpperCase',
            '9 AcronymNotLikeWord',
            '11 ConstantNotUpperCase',
        ];
        $this->assertSame(['9 AcronymNotLikeWord'], $reports['PERCS31']);
        $this->assertEqualsCanonicalizing($jumbo, $reports['Jumbo']);
    }

    /**
     * Test that a constant with a double underscore passes in PERCS31, and is an error in Jumbo, which requires strict
     * UPPER_SNAKE_CASE of a class constant, a global constant and a constant from define().
     */
    public function testStrictUpperSnakeCaseIsRequiredInJumboOnly(): void
    {
        $source = <<<'TEXT'
            <?php

            const TWO__WORDS = 1;

            define('_LEADING', 2);

            class Holder
            {
                public const __HIDDEN = 3;
                public const VALID_NAME = 4;
            }

            TEXT;
        $file = $this->copyToTempFile(null, $source);
        $sniff = '--sniffs=PERCS31.NamingConventions.ValidConstantName';
        $reports = [];

        try {
            foreach (['PERCS31', 'Jumbo'] as $standard) {
                $args = ["--standard={$standard}", $sniff, '-s', '--report=emacs', $file];
                $output = $this->runTool('phpcs', $args)['output'];
                preg_match_all('/:(\\d+):\\d+: error - .*ConstantNotUpperSnakeCase\\)$/m', $output, $matches);
                $reports[$standard] = $matches[1];
            }
        } finally {
            unlink($file);
        }

        $this->assertSame([], $reports['PERCS31']);
        $this->assertSame(['3', '5', '9'], $reports['Jumbo']);
    }

    /**
     * Test that a leading underscore on a global constant passes in PERCS31, as PER's rule is about visibility, and is
     * an error in Jumbo.
     */
    public function testGlobalConstantUnderscoreIsCheckedInJumboOnly(): void
    {
        $file = $this->copyToTempFile(null, "<?php\n\nconst _HIDDEN = 1;\n");
        $sniff = '--sniffs=PERCS31.NamingConventions.ValidConstantName';

        try {
            $per = $this->runTool('phpcs', ['--standard=PERCS31', $sniff, '-s', '--report=emacs', $file])['output'];
            $jumbo = $this->runTool('phpcs', ['--standard=Jumbo', $sniff, '-s', '--report=emacs', $file])['output'];
        } finally {
            unlink($file);
        }

        $this->assertStringNotContainsString('ValidConstantName', $per);
        $this->assertStringContainsString('PERCS31.NamingConventions.ValidConstantName.Underscore', $jumbo);
    }

    /**
     * Test that two or more attribute blocks go on separate lines in both standards, with the target on the next
     * line, except in a single-line list, where they stay inline, and that phpcbf gives the same result in each.
     */
    #[DataProvider('standardProvider')]
    public function testSeparateAttributeBlocksAreSplit(string $standard): void
    {
        $source = <<<'PHP'
            <?php

            declare(strict_types=1);

            enum E
            {
                #[A]  #[B] case X;

                #[A, B] case Z;
            }

            function f(#[A] #[B] int $c): void {}

            usort($items, #[A] #[B] static fn($x) => $x);

            $pure = #[A] #[B] static fn($x) => $x;

            PHP;
        $expected = <<<'PHP'
            <?php

            declare(strict_types=1);

            enum E
            {
                #[A]
                #[B]
                case X;

                #[A, B]
                case Z;
            }

            function f(#[A] #[B] int $c): void {}

            usort($items, #[A] #[B] static fn($x) => $x);

            $pure = #[A]
                #[B]
                static fn($x) => $x;

            PHP;
        $file = $this->copyToTempFile(null, $source);

        try {
            $this->runTool('phpcbf', ["--standard={$standard}", $file]);
            $this->assertSame($expected, file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * Test that a leading underscore on a property is an error in both standards, as LeadingUnderscore, not the
     * warning from PSR-2's sniff, which PERCS31.Classes.PropertyDeclaration extends, and that its excluded order check
     * isn't reported.
     */
    #[DataProvider('standardProvider')]
    public function testPropertyUnderscoreIsAnError(string $standard): void
    {
        $source = <<<'TEXT'
            <?php

            namespace App;

            class A
            {
                public string $_a = 'x';

                static public int $b = 1;
            }

            TEXT;
        $file = $this->copyToTempFile(null, $source);

        try {
            $args = ['-s', "--standard={$standard}", '--report=emacs', $file];
            $output = $this->runTool('phpcs', $args)['output'];
        } finally {
            unlink($file);
        }

        $this->assertMatchesRegularExpression('/:7:\\d+: error - .*PropertyDeclaration\\.LeadingUnderscore/', $output);
        $this->assertStringNotContainsString('(PERCS31.Classes.PropertyDeclaration.Underscore)', $output);
        $this->assertStringNotContainsString('PERCS31.Classes.PropertyDeclaration.StaticBeforeVisibility', $output);
    }

    /**
     * The two standards.
     *
     * @return array<string, array{string}>
     */
    public static function standardProvider(): array
    {
        return ['PERCS31' => ['PERCS31'], 'Jumbo' => ['Jumbo']];
    }

    #endregion
}
