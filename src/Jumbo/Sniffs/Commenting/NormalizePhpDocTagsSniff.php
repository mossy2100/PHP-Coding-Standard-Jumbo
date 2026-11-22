<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\UseStatement;
use SlevomatCodingStandard\Helpers\UseStatementHelper;

/**
 * Normalizes docblock tag names, and the format of inline tags.
 *
 * PER Coding Style 3.1 has no rule for docblocks, so this is an extra rule. Three things are checked, each with its own
 * code:
 *
 * - Casing: a known tag name in its usual case, such as `return` for `Return`. The names are the ones
 *   SlevomatCodingStandard.Commenting.AnnotationName knows, so `inheritDoc` is written in camel case.
 * - Alias: an alias replaced by its main tag, in any case: `inheritDocs` by `inheritDoc`, `returns` by `return`, `type`
 *   and `const` by `var`, and `link` by `see`.
 * - InlineFormat: an inline tag written as `{@name description}`, with the `@` inside a single brace at each end, and
 *   no space after the opening brace or before the closing one. Only the tags that can be inline are checked.
 *
 * Block tags and inline tags are both checked. A block tag that a `use` statement imports, such as a Doctrine or JMS
 * annotation, is left alone, as is a name followed by `(` or `\`. Text that only looks like a tag, in the middle of a
 * sentence, isn't checked, nor is an inline tag split over two lines.
 *
 * The tag names are from SlevomatCodingStandard.Commenting.AnnotationName, which this sniff replaces in Jumbo, as its
 * block tag check skips every tag in a namespaced file. The inline format check is adapted from PHP-CS-Fixer's
 * phpdoc_inline_tag_normalizer.
 *
 * @copyright 2015 Slevomat.cz, s.r.o. (the tag names)
 * @copyright Fabien Potencier and Dariusz Rumiński (the inline format check)
 * @license   MIT
 */
class NormalizePhpDocTagsSniff implements Sniff
{
    public const string CODE_CASING = 'Casing';

    public const string CODE_ALIAS = 'Alias';

    public const string CODE_INLINE_FORMAT = 'InlineFormat';

    /**
     * Aliases, in lower case, and the main tag that replaces each one.
     */
    private const array ALIASES = [
        'const' => 'var',
        'inheritdocs' => 'inheritDoc',
        'link' => 'see',
        'returns' => 'return',
        'type' => 'var',
    ];

    /**
     * The tags whose inline format is checked. A longer name comes before a shorter one it starts with.
     */
    private const array INLINE_TAGS = [
        'example',
        'id',
        'inheritdocs',
        'inheritdoc',
        'internal',
        'link',
        'see',
        'source',
        'toc',
        'tutorial',
    ];

    private const array STANDARD_TAGS = [
        'api',
        'author',
        'category',
        'copyright',
        'deprecated',
        'example',
        'filesource',
        'global',
        'ignore',
        'inheritDoc',
        'internal',
        'license',
        'link',
        'method',
        'package',
        'param',
        'property',
        'property-read',
        'property-write',
        'return',
        'see',
        'since',
        'source',
        'subpackage',
        'throws',
        'todo',
        'uses',
        'used-by',
        'var',
        'version',
    ];

    private const array STATIC_ANALYSIS_TAGS = [
        'api',
        'allow-private-mutation',
        'assert',
        'assert-if-true',
        'assert-if-false',
        'consistent-constructor',
        'consistent-templates',
        'extends',
        'external-mutation-free',
        'implements',
        'mixin',
        'ignore-falsable-return',
        'ignore-nullable-return',
        'ignore-var',
        'ignore-variable-method',
        'ignore-variable-property',
        'immutable',
        'import-type',
        'internal',
        'method',
        'mutation-free',
        'no-named-arguments',
        'param',
        'param-out',
        'property',
        'property-read',
        'property-write',
        'psalm-check-type',
        'psalm-check-type-exact',
        'psalm-suppress',
        'psalm-trace',
        'pure',
        'readonly',
        'readonly-allow-private-mutation',
        'require-extends',
        'require-implements',
        'return',
        'seal-properties',
        'self-out',
        'template',
        'template-covariant',
        'template-extends',
        'template-implements',
        'template-use',
        'this-out',
        'type',
        'var',
        'yield',
    ];

    private const array PHPUNIT_TAGS = [
        'author',
        'after',
        'afterClass',
        'backupGlobals',
        'backupStaticAttributes',
        'before',
        'beforeClass',
        'codeCoverageIgnore',
        'codeCoverageIgnoreStart',
        'codeCoverageIgnoreEnd',
        'covers',
        'coversDefaultClass',
        'coversNothing',
        'dataProvider',
        'depends',
        'doesNotPerformAssertions',
        'group',
        'large',
        'medium',
        'preserveGlobalState',
        'requires',
        'runTestsInSeparateProcesses',
        'runInSeparateProcess',
        'small',
        'test',
        'testdox',
        'testWith',
        'ticket',
        'uses',
    ];

    /**
     * The prefixes added to each static analysis tag, except the ones starting with `psalm`.
     */
    private const array STATIC_ANALYSIS_PREFIXES = ['psalm', 'phpstan'];

    /**
     * Every known tag name, keyed by its lower-case form.
     *
     * @var array<string, string>|null
     */
    private ?array $tagNames = null;

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_DOC_COMMENT_OPEN_TAG];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $closer = $tokens[$stackPtr]['comment_closer'];
        $first = null;
        $last = null;
        for ($i = $stackPtr + 1; $i < $closer; $i++) {
            if ($tokens[$i]['code'] !== T_DOC_COMMENT_TAG && $tokens[$i]['code'] !== T_DOC_COMMENT_STRING) {
                continue;
            }

            if ($first !== null && $last !== null && $tokens[$first]['line'] !== $tokens[$i]['line']) {
                $this->processLine($phpcsFile, $first, $last);
                $first = null;
            }

            $first ??= $i;
            $last = $i;
        }

        if ($first !== null && $last !== null) {
            $this->processLine($phpcsFile, $first, $last);
        }
    }

    /**
     * Checks the text of one docblock line, and fixes it in one change.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $first The line's first tag or string token.
     * @param int $last The line's last tag or string token.
     */
    private function processLine(File $phpcsFile, int $first, int $last): void
    {
        $tokens = $phpcsFile->getTokens();
        $text = '';
        for ($i = $first; $i <= $last; $i++) {
            $text .= $tokens[$i]['content'];
        }

        $fixed = $this->fixInlineFormat($phpcsFile, $first, $text);
        if ($tokens[$first]['code'] === T_DOC_COMMENT_TAG) {
            $fixed = $this->fixBlockTagName($phpcsFile, $first, $fixed);
        }

        $fixed = $this->fixInlineTagNames($phpcsFile, $first, $fixed);
        if ($fixed === $text) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($first, $fixed);
        for ($i = $first + 1; $i <= $last; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Reports each badly formatted inline tag in the text, and returns the text with the fixes made.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The token to report on.
     * @param string $text The line's text.
     * @return string The text, fixed.
     */
    private function fixInlineFormat(File $phpcsFile, int $stackPtr, string $text): string
    {
        $pattern = '/(?:@\{+|\{+\h*@)\h*(' . implode('|', self::INLINE_TAGS) . ')\b([^}]*)\}+/i';
        $callback = static function (array $matches) use ($phpcsFile, $stackPtr): string {
            $description = trim($matches[2]);
            $expected = "{@{$matches[1]}" . ($description === '' ? '' : ' ' . $description) . '}';
            if ($expected === $matches[0]) {
                return $matches[0];
            }

            $error = 'Expected inline tag %s; found %s';
            $data = [$expected, $matches[0]];
            $fix = $phpcsFile->addFixableError($error, $stackPtr, self::CODE_INLINE_FORMAT, $data);

            return $fix ? $expected : $matches[0];
        };

        return preg_replace_callback($pattern, $callback, $text) ?? $text;
    }

    /**
     * Reports a block tag's name if it's in the wrong case or an alias, and returns the text with the fix made.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The tag token.
     * @param string $text The line's text, starting with the tag.
     * @return string The text, fixed.
     */
    private function fixBlockTagName(File $phpcsFile, int $stackPtr, string $text): string
    {
        if (preg_match('/^@([A-Za-z][\w-]*)(?=\s|$)/', $text, $matches) !== 1) {
            return $text;
        }

        $name = $matches[1];
        $expected = $this->expectedName($name);
        if ($expected === null || $this->isImported($phpcsFile, $stackPtr, $name)) {
            return $text;
        }

        if (!$this->reportName($phpcsFile, $stackPtr, $name, $expected)) {
            return $text;
        }

        return "@{$expected}" . substr($text, strlen($matches[0]));
    }

    /**
     * Reports each inline tag name in the text that's in the wrong case or an alias, and returns the text with the
     * fixes made.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The token to report on.
     * @param string $text The line's text.
     * @return string The text, fixed.
     */
    private function fixInlineTagNames(File $phpcsFile, int $stackPtr, string $text): string
    {
        $callback = function (array $matches) use ($phpcsFile, $stackPtr): string {
            $expected = $this->expectedName($matches[1]);
            if ($expected === null || !$this->reportName($phpcsFile, $stackPtr, $matches[1], $expected)) {
                return $matches[0];
            }

            return "{@{$expected}";
        };

        return preg_replace_callback('/\{@([A-Za-z][\w-]*)(?=[\s}]|$)/', $callback, $text) ?? $text;
    }

    /**
     * Returns the name a tag should have, or null if it's correct or unknown.
     *
     * @param string $name The tag's name, without the `@`.
     * @return ?string The expected name, without the `@`.
     */
    private function expectedName(string $name): ?string
    {
        $lower = strtolower($name);
        $expected = self::ALIASES[$lower] ?? $this->getTagNames()[$lower] ?? null;

        return $expected === $name ? null : $expected;
    }

    /**
     * Reports a tag name that's in the wrong case or an alias.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The token to report on.
     * @param string $name The tag's name, without the `@`.
     * @param string $expected The expected name, without the `@`.
     * @return bool Whether to fix it.
     */
    private function reportName(File $phpcsFile, int $stackPtr, string $name, string $expected): bool
    {
        if (isset(self::ALIASES[strtolower($name)])) {
            $error = 'Expected @%s; found the alias @%s';

            return $phpcsFile->addFixableError($error, $stackPtr, self::CODE_ALIAS, [$expected, $name]);
        }

        $error = 'Expected @%s; found @%s';

        return $phpcsFile->addFixableError($error, $stackPtr, self::CODE_CASING, [$expected, $name]);
    }

    /**
     * Checks whether a `use` statement imports a tag's name, as for a Doctrine or JMS annotation.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The tag token.
     * @param string $name The tag's name, without the `@`.
     * @return bool True if the name is imported.
     */
    private function isImported(File $phpcsFile, int $stackPtr, string $name): bool
    {
        $useStatements = UseStatementHelper::getUseStatementsForPointer($phpcsFile, $stackPtr);

        return isset($useStatements[UseStatement::getUniqueId(UseStatement::TYPE_CLASS, $name)]);
    }

    /**
     * Returns every known tag name, keyed by its lower-case form.
     *
     * @return array<string, string>
     */
    private function getTagNames(): array
    {
        if ($this->tagNames !== null) {
            return $this->tagNames;
        }

        $names = [...self::STANDARD_TAGS, ...self::PHPUNIT_TAGS, ...self::STATIC_ANALYSIS_TAGS];
        foreach (self::STATIC_ANALYSIS_TAGS as $name) {
            if (str_starts_with($name, 'psalm')) {
                continue;
            }

            foreach (self::STATIC_ANALYSIS_PREFIXES as $prefix) {
                $names[] = "{$prefix}-{$name}";
            }
        }

        $this->tagNames = [];
        foreach ($names as $name) {
            $this->tagNames[strtolower($name)] = $name;
        }

        return $this->tagNames;
    }
}
