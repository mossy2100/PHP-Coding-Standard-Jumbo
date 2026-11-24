<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PhpUnit;

use Jumbo\Helpers\PhpUnitHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use SlevomatCodingStandard\Helpers\DocCommentHelper;

/**
 * Checks a PHPUnit test class's declarations: no PHPUnit annotations, and `setUp()` and the other hooks `protected`.
 *
 * PHPUnit 12 reads attributes only, so an annotation in a doc comment is silently ignored: a method marked only `@test`
 * never runs, and `@dataProvider`, `@depends` or `@covers` quietly do nothing. So each PHPUnit annotation is reported:
 * - `@test` is fixed to a `test` prefix on the method's name, as in `testAddsTwoNumbers()`. A `#[Depends]` or
 *   `@depends` naming the method in the class is renamed too.
 * - Any other is fixed to its attribute, written fully qualified for
 *   SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly to import: `@dataProvider provideCases` to
 *   `#[\PHPUnit\Framework\Attributes\DataProvider('provideCases')]`. `@coversDefaultClass` has no attribute, so it's
 *   removed, and a class-level `@covers ::method` names the class instead.
 * - An annotation with no attribute where it is, such as `@covers` on a method, or one whose value can't be read, is
 *   reported but not fixed.
 *
 * `setUp()`, `tearDown()`, `setUpBeforeClass()` and `tearDownAfterClass()` declared `public` are fixed to `protected`,
 * as PHPUnit's `TestCase` declares them.
 *
 * A test class is one that extends `PHPUnit\Framework\TestCase`, resolved through the file's `use` statements.
 * `@author`, which PHPUnit also reads as a group, is left alone, as it's a general doc tag.
 */
class TestClassSniff implements Sniff
{
    public const string CODE_ANNOTATION = 'Annotation';

    public const string CODE_TEST_ANNOTATION = 'TestAnnotation';

    public const string CODE_HOOK_VISIBILITY = 'HookVisibility';

    /**
     * The namespace of PHPUnit's attributes.
     */
    private const string ATTRIBUTES = '\PHPUnit\Framework\Attributes\\';

    /**
     * Annotations whose attribute takes no arguments, by lower-case name, with the attribute's name.
     *
     * @var array<string, string>
     */
    private const array WITHOUT_ARGUMENTS = [
        'after' => 'After',
        'afterclass' => 'AfterClass',
        'before' => 'Before',
        'beforeclass' => 'BeforeClass',
        'coversnothing' => 'CoversNothing',
        'doesnotperformassertions' => 'DoesNotPerformAssertions',
        'large' => 'Large',
        'medium' => 'Medium',
        'small' => 'Small',
        'postcondition' => 'PostCondition',
        'precondition' => 'PreCondition',
        'runinseparateprocess' => 'RunInSeparateProcess',
        'runtestsinseparateprocesses' => 'RunTestsInSeparateProcesses',
        'withouterrorhandler' => 'WithoutErrorHandler',
    ];

    /**
     * Annotations whose attribute takes their whole value as one string, by lower-case name.
     *
     * @var array<string, string>
     */
    private const array WITH_TEXT = [
        'group' => 'Group',
        'testdox' => 'TestDox',
        'ticket' => 'Ticket',
        'excludeglobalvariablefrombackup' => 'ExcludeGlobalVariableFromBackup',
    ];

    /**
     * Annotations whose value is `enabled` or `disabled`, by lower-case name.
     *
     * @var array<string, string>
     */
    private const array ENABLED_OR_DISABLED = [
        'backupglobals' => 'BackupGlobals',
        'backupstaticattributes' => 'BackupStaticProperties',
        'preserveglobalstate' => 'PreserveGlobalState',
    ];

    /**
     * The other PHPUnit annotations, by lower-case name, each read by its own method.
     *
     * @var list<string>
     */
    private const array OTHERS = [
        'covers',
        'coversdefaultclass',
        'uses',
        'dataprovider',
        'depends',
        'requires',
        'testwith',
        'excludestaticpropertyfrombackup',
        'test',
    ];

    /**
     * Annotations that only have an attribute on a method, by lower-case name.
     *
     * @var list<string>
     */
    private const array METHOD_ONLY = [
        'after',
        'afterclass',
        'before',
        'beforeclass',
        'dataprovider',
        'depends',
        'postcondition',
        'precondition',
        'runinseparateprocess',
        'test',
        'testwith',
    ];

    /**
     * Annotations that only have an attribute on a class, by lower-case name.
     *
     * @var list<string>
     */
    private const array CLASS_ONLY = [
        'covers',
        'coversdefaultclass',
        'uses',
        'large',
        'medium',
        'small',
        'runtestsinseparateprocesses',
    ];

    /**
     * The `@requires` kinds, with their attributes.
     *
     * @var array<string, string>
     */
    private const array REQUIRES = [
        'extension' => 'RequiresPhpExtension',
        'function' => 'RequiresFunction',
        'PHP' => 'RequiresPhp',
        'PHPUnit' => 'RequiresPhpunit',
        'OS' => 'RequiresOperatingSystem',
        'OSFAMILY' => 'RequiresOperatingSystemFamily',
        'setting' => 'RequiresSetting',
    ];

    /**
     * The attributes whose argument is a method in the same class.
     *
     * @var list<string>
     */
    private const array DEPENDS_ON_METHOD = ['Depends', 'DependsUsingDeepClone', 'DependsUsingShallowClone'];

    /**
     * The hook methods `TestCase` declares `protected`, by lower-case name.
     *
     * @var list<string>
     */
    private const array HOOKS = ['setup', 'teardown', 'setupbeforeclass', 'teardownafterclass'];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_CLASS];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `class` keyword.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        if (!PhpUnitHelper::isTestCase($phpcsFile, $stackPtr)) {
            return;
        }

        $methods = PhpUnitHelper::findMethods($phpcsFile, $stackPtr);
        $renames = $this->findRenames($phpcsFile, $methods);
        $defaultClass = $this->findDefaultClass($phpcsFile, $stackPtr);
        $this->processDocComment($phpcsFile, $stackPtr, $stackPtr, $renames, $defaultClass);
        foreach ($methods as $method) {
            $this->processDocComment($phpcsFile, $stackPtr, $method, $renames, $defaultClass);
            $this->processHookVisibility($phpcsFile, $method);
        }
    }

    /**
     * Work out the new name of each method marked `@test` without a `test` prefix.
     *
     * @param File $phpcsFile The file being scanned.
     * @param list<int> $methods The positions of the class's methods.
     * @return array<string, string> Each method's name => its new name, for those that can be renamed.
     */
    private function findRenames(File $phpcsFile, array $methods): array
    {
        $names = [];
        foreach ($methods as $method) {
            $names[strtolower((string) $phpcsFile->getDeclarationName($method))] = true;
        }

        $renames = [];
        foreach ($methods as $method) {
            $name = (string) $phpcsFile->getDeclarationName($method);
            $hasTest = false;
            foreach ($this->findTags($phpcsFile, $method) as $tag) {
                $hasTest = $hasTest || strtolower($tag['name']) === 'test';
            }

            $newName = 'test' . ucfirst($name);
            if ($hasTest && !str_starts_with($name, 'test') && !isset($names[strtolower($newName)])) {
                $renames[$name] = $newName;
            }
        }

        return $renames;
    }

    /**
     * Find the class a test class's `@coversDefaultClass` names.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the `class` keyword.
     * @return ?string The class name, or null if there's none.
     */
    private function findDefaultClass(File $phpcsFile, int $classPtr): ?string
    {
        foreach ($this->findTags($phpcsFile, $classPtr) as $tag) {
            if (strtolower($tag['name']) === 'coversdefaultclass' && $tag['value'] !== '') {
                return $tag['value'];
            }
        }

        return null;
    }

    /**
     * Find the tags in a declaration's doc comment, with their values.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $declaration The position of the `class` or `function` keyword.
     * @return list<array{tag: int, name: string, value: string, lines: list<string>, last: int}> Each tag's position,
     *     its name without the `@`, its value on its own line, every line of its value, and its last line.
     */
    private function findTags(File $phpcsFile, int $declaration): array
    {
        $opener = DocCommentHelper::findDocCommentOpenPointer($phpcsFile, $declaration);
        if ($opener === null) {
            return [];
        }

        $tokens = $phpcsFile->getTokens();
        $closer = $tokens[$opener]['comment_closer'];
        $tags = [];
        $tagPointers = $tokens[$opener]['comment_tags'];
        foreach ($tagPointers as $index => $tag) {
            $end = $tagPointers[($index + 1)] ?? $closer;
            $lines = [];
            $value = '';
            $last = $tokens[$tag]['line'];
            for ($i = ($tag + 1); $i < $end; $i++) {
                if ($tokens[$i]['code'] !== T_DOC_COMMENT_STRING) {
                    continue;
                }

                // A line of the value: the tag's own, or one after it before a blank line.
                if ($tokens[$i]['line'] !== $last && $tokens[$i]['line'] !== ($last + 1)) {
                    break;
                }

                $lines[] = trim($tokens[$i]['content']);
                $value = $tokens[$i]['line'] === $tokens[$tag]['line'] ? trim($tokens[$i]['content']) : $value;
                $last = $tokens[$i]['line'];
            }

            $tags[] = [
                'tag' => $tag,
                'name' => substr($tokens[$tag]['content'], 1),
                'value' => $value,
                'lines' => $lines,
                'last' => $last,
            ];
        }

        return $tags;
    }

    /**
     * Report the PHPUnit annotations in a declaration's doc comment, and fix those that can be.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the `class` keyword.
     * @param int $declaration The position of the `class` or `function` keyword.
     * @param array<string, string> $renames Each method marked `@test` that's renamed => its new name.
     * @param ?string $defaultClass The class `@coversDefaultClass` names, if any.
     */
    private function processDocComment(
        File $phpcsFile,
        int $classPtr,
        int $declaration,
        array $renames,
        ?string $defaultClass,
    ): void {
        $tokens = $phpcsFile->getTokens();
        $isMethod = $declaration !== $classPtr;
        $attributes = [];
        $removeLines = [];
        $rename = null;
        foreach ($this->findTags($phpcsFile, $declaration) as $tag) {
            $name = strtolower($tag['name']);
            if (!$this->isPhpUnitAnnotation($name)) {
                continue;
            }

            $wrongPlace = in_array($name, $isMethod ? self::CLASS_ONLY : self::METHOD_ONLY, true);
            if ($name === 'test') {
                $methodName = (string) $phpcsFile->getDeclarationName($declaration);
                $fixable = !$wrongPlace && (str_starts_with($methodName, 'test') || isset($renames[$methodName]));
                $error = 'PHPUnit 12 ignores the @test annotation; start the method\'s name with "test" instead';
                if ($this->report($phpcsFile, $error, $tag['tag'], self::CODE_TEST_ANNOTATION, $fixable)) {
                    $removeLines = [...$removeLines, ...range($tokens[$tag['tag']]['line'], $tag['last'])];
                    $rename = isset($renames[$methodName]) ? [$methodName => $renames[$methodName]] : null;
                }

                continue;
            }

            $new = $wrongPlace ? null : $this->toAttributes($name, $tag, $renames, $defaultClass);
            $error = 'PHPUnit 12 ignores the @%s annotation; use %s';
            $data = [$tag['name'], $this->describe($new, $wrongPlace, $isMethod)];
            $fix = $this->report($phpcsFile, $error, $tag['tag'], self::CODE_ANNOTATION, $new !== null, $data);
            if ($fix && $new !== null) {
                $attributes = [...$attributes, ...$new];
                $removeLines = [...$removeLines, ...range($tokens[$tag['tag']]['line'], $tag['last'])];
            }
        }

        if ($removeLines === []) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $this->removeLines($phpcsFile, $declaration, $removeLines);
        $this->insertAttributes($phpcsFile, $declaration, $attributes);
        if ($rename !== null) {
            $namePtr = $phpcsFile->findNext(T_STRING, ($declaration + 1));
            if ($namePtr !== false) {
                $phpcsFile->fixer->replaceToken($namePtr, reset($rename));
            }

            $this->renameDependsAttributes($phpcsFile, $classPtr, $rename);
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Describe what to use in place of an annotation, for its error message.
     *
     * @param list<string>|null $attributes The attributes it's fixed to, or null if it isn't fixable.
     * @param bool $wrongPlace Whether it has no attribute where it is.
     * @param bool $isMethod Whether it's on a method, rather than the class.
     * @return string The description.
     */
    private function describe(?array $attributes, bool $wrongPlace, bool $isMethod): string
    {
        if ($wrongPlace) {
            return $isMethod ? 'an attribute on the class instead' : 'an attribute on a method instead';
        }

        if ($attributes === null) {
            return 'its attribute instead; its value couldn\'t be read';
        }

        if ($attributes === []) {
            return 'nothing; it has no attribute';
        }

        return implode(' and ', array_map(
            static fn(string $attribute): string => str_replace(self::ATTRIBUTES, '', $attribute),
            $attributes,
        )) . ' instead';
    }

    /**
     * Report an annotation, as fixable or not.
     *
     * @param File $phpcsFile The file being scanned.
     * @param string $error The message.
     * @param int $ptr The position of the tag.
     * @param string $code The error code.
     * @param bool $fixable Whether it can be fixed.
     * @param list<string> $data The message's values.
     * @return bool True if it's to be fixed now.
     */
    private function report(
        File $phpcsFile,
        string $error,
        int $ptr,
        string $code,
        bool $fixable,
        array $data = [],
    ): bool {
        if (!$fixable) {
            $phpcsFile->addError($error, $ptr, $code, $data);

            return false;
        }

        return $phpcsFile->addFixableError($error, $ptr, $code, $data);
    }

    /**
     * Check whether a tag is one of PHPUnit's annotations.
     *
     * @param string $name The tag's lower-case name, without the `@`.
     * @return bool True if it is.
     */
    private function isPhpUnitAnnotation(string $name): bool
    {
        return isset(self::WITHOUT_ARGUMENTS[$name]) || isset(self::WITH_TEXT[$name])
            || isset(self::ENABLED_OR_DISABLED[$name]) || in_array($name, self::OTHERS, true);
    }

    /**
     * Work out the attributes an annotation is fixed to.
     *
     * @param string $name The tag's lower-case name, without the `@`.
     * @param array{tag: int, name: string, value: string, lines: list<string>, last: int} $tag The tag.
     * @param array<string, string> $renames Each method marked `@test` that's renamed => its new name.
     * @param ?string $defaultClass The class `@coversDefaultClass` names, if any.
     * @return list<string>|null The attributes, which may be none, or null if its value can't be read.
     */
    private function toAttributes(string $name, array $tag, array $renames, ?string $defaultClass): ?array
    {
        $value = $tag['value'];
        $words = $value === '' ? [] : preg_split('/\s+/', $value);
        $words = is_array($words) ? $words : [];

        return match (true) {
            isset(self::WITHOUT_ARGUMENTS[$name]) => [$this->attribute(self::WITHOUT_ARGUMENTS[$name])],
            isset(self::WITH_TEXT[$name]) => $value === ''
                ? null
                : [$this->attribute(self::WITH_TEXT[$name], self::quote($value))],
            isset(self::ENABLED_OR_DISABLED[$name]) => in_array($value, ['enabled', 'disabled'], true)
                ? [$this->attribute(self::ENABLED_OR_DISABLED[$name], $value === 'enabled' ? 'true' : 'false')]
                : null,
            $name === 'covers', $name === 'uses' => $this->coversOrUses($name, $words, $defaultClass),
            $name === 'coversdefaultclass' => [],
            $name === 'dataprovider' => $this->dataProvider($words),
            $name === 'depends' => $this->depends($words, $renames),
            $name === 'requires' => $this->requires($words, $value),
            $name === 'testwith' => $this->testWith($tag['lines']),
            $name === 'excludestaticpropertyfrombackup' => $this->excludeStaticProperty($words),
            default => null,
        };
    }

    /**
     * Work out the attributes for a `@testWith` annotation, one for each line of its value.
     *
     * @param list<string> $lines The lines of its value, each a JSON array of arguments.
     * @return list<string>|null The attributes, or null if it has no value.
     */
    private function testWith(array $lines): ?array
    {
        if ($lines === []) {
            return null;
        }

        return array_map(fn(string $line): string => $this->attribute('TestWithJson', self::quote($line)), $lines);
    }

    /**
     * Work out the attribute for an `@excludeStaticPropertyFromBackup` annotation.
     *
     * @param list<string> $words The words of its value: the class and the property.
     * @return list<string>|null The attribute, or null if the value can't be read.
     */
    private function excludeStaticProperty(array $words): ?array
    {
        if (count($words) !== 2) {
            return null;
        }

        $arguments = self::quote($words[0]) . ', ' . self::quote($words[1]);

        return [$this->attribute('ExcludeStaticPropertyFromBackup', $arguments)];
    }

    /**
     * Work out the attribute for a `@covers` or `@uses` annotation.
     *
     * @param string $name `covers` or `uses`.
     * @param list<string> $words The words of its value.
     * @param ?string $defaultClass The class `@coversDefaultClass` names, if any.
     * @return list<string>|null The attribute, or null if the value can't be read.
     */
    private function coversOrUses(string $name, array $words, ?string $defaultClass): ?array
    {
        $prefix = $name === 'covers' ? 'Covers' : 'Uses';
        if (
            count($words) !== 1 || !preg_match('/^(\\\\?[A-Za-z_][\w\\\\]*)?(?:::([A-Za-z_]\w*))?$/', $words[0], $match)
        ) {
            return null;
        }

        $class = $match[1] ?? '';
        $member = $match[2] ?? '';
        if ($class === '' && $member === '') {
            return null;
        }

        if ($class === '' && $defaultClass !== null) {
            $class = $defaultClass;
        }

        if ($member === '') {
            return [$this->attribute("{$prefix}Class", "{$class}::class")];
        }

        if ($class === '') {
            return [$this->attribute("{$prefix}Function", self::quote($member))];
        }

        return [$this->attribute("{$prefix}Method", "{$class}::class, " . self::quote($member))];
    }

    /**
     * Work out the attribute for a `@dataProvider` annotation.
     *
     * @param list<string> $words The words of its value.
     * @return list<string>|null The attribute, or null if the value can't be read.
     */
    private function dataProvider(array $words): ?array
    {
        $provider = rtrim($words[0] ?? '', '()');
        if (
            count($words) !== 1 || !preg_match('/^(?:(\\\\?[A-Za-z_][\w\\\\]*)::)?([A-Za-z_]\w*)$/', $provider, $match)
        ) {
            return null;
        }

        if ($match[1] === '') {
            return [$this->attribute('DataProvider', self::quote($match[2]))];
        }

        return [$this->attribute('DataProviderExternal', "{$match[1]}::class, " . self::quote($match[2]))];
    }

    /**
     * Work out the attribute for a `@depends` annotation.
     *
     * @param list<string> $words The words of its value.
     * @param array<string, string> $renames Each method marked `@test` that's renamed => its new name.
     * @return list<string>|null The attribute, or null if the value can't be read.
     */
    private function depends(array $words, array $renames): ?array
    {
        $suffix = '';
        if (count($words) === 2 && in_array($words[0], ['clone', 'shallowClone'], true)) {
            $suffix = $words[0] === 'clone' ? 'UsingDeepClone' : 'UsingShallowClone';
            $words = [$words[1]];
        }

        $target = rtrim($words[0] ?? '', '()');
        if (count($words) !== 1 || !preg_match('/^(?:(\\\\?[A-Za-z_][\w\\\\]*)::)?([A-Za-z_]\w*)$/', $target, $match)) {
            return null;
        }

        [, $class, $method] = $match;
        if ($class === '') {
            return [$this->attribute("Depends{$suffix}", self::quote($renames[$method] ?? $method))];
        }

        if ($method === 'class') {
            return [$this->attribute("DependsOnClass{$suffix}", "{$class}::class")];
        }

        return [$this->attribute("DependsExternal{$suffix}", "{$class}::class, " . self::quote($method))];
    }

    /**
     * Work out the attribute for a `@requires` annotation.
     *
     * @param list<string> $words The words of its value.
     * @param string $value Its whole value.
     * @return list<string>|null The attribute, or null if the value can't be read.
     */
    private function requires(array $words, string $value): ?array
    {
        $kind = $words[0] ?? '';
        if (count($words) < 2 || !isset(self::REQUIRES[$kind])) {
            return null;
        }

        $rest = trim(substr($value, strlen($kind)));
        $attribute = self::REQUIRES[$kind];
        if ($kind === 'PHP' || $kind === 'PHPUnit') {
            return [$this->attribute($attribute, self::quote(self::versionConstraint($rest)))];
        }

        if ($kind === 'function' && str_contains($words[1], '::')) {
            [$class, $method] = explode('::', $words[1], 2);

            return count($words) === 2
                ? [$this->attribute('RequiresMethod', "{$class}::class, " . self::quote($method))]
                : null;
        }

        if ($kind === 'extension' && count($words) === 3) {
            $arguments = self::quote($words[1]) . ', ' . self::quote(self::versionConstraint($words[2]));

            return [$this->attribute($attribute, $arguments)];
        }

        if ($kind === 'setting' && count($words) === 3) {
            return [$this->attribute($attribute, self::quote($words[1]) . ', ' . self::quote($words[2]))];
        }

        return count($words) === 2 ? [$this->attribute($attribute, self::quote($words[1]))] : null;
    }

    /**
     * Write a bare version, such as `8.1`, as the `>=` constraint PHPUnit read it as.
     *
     * @param string $version The version or constraint.
     * @return string The constraint.
     */
    private static function versionConstraint(string $version): string
    {
        return preg_match('/^[\d.-]+(dev|(RC|alpha|beta)[\d.])?$/', $version) === 1 ? ">= {$version}" : $version;
    }

    /**
     * Write an attribute, fully qualified.
     *
     * @param string $name The attribute's short name.
     * @param ?string $arguments Its arguments' code, if any.
     * @return string The attribute's code.
     */
    private function attribute(string $name, ?string $arguments = null): string
    {
        return '#[' . self::ATTRIBUTES . $name . ($arguments === null ? '' : "({$arguments})") . ']';
    }

    /**
     * Write a value as a single-quoted string literal.
     *
     * @param string $value The value.
     * @return string The literal.
     */
    private static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Remove lines from a declaration's doc comment, then a blank line left before its end, or the whole doc comment
     * if nothing's left.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $declaration The position of the `class` or `function` keyword.
     * @param list<int> $removeLines The line numbers to remove.
     */
    private function removeLines(File $phpcsFile, int $declaration, array $removeLines): void
    {
        $tokens = $phpcsFile->getTokens();
        $opener = (int) DocCommentHelper::findDocCommentOpenPointer($phpcsFile, $declaration);
        $closer = $tokens[$opener]['comment_closer'];
        $remove = array_fill_keys($removeLines, true);

        // The lines left with text, and whether each other line between the ends is blank.
        $kept = [];
        for ($i = ($opener + 1); $i < $closer; $i++) {
            $line = $tokens[$i]['line'];
            if (
                !isset($remove[$line]) && in_array($tokens[$i]['code'], [T_DOC_COMMENT_STRING, T_DOC_COMMENT_TAG], true)
            ) {
                $kept[$line] = true;
            }
        }

        if ($kept === []) {
            $next = $phpcsFile->findNext(T_WHITESPACE, ($closer + 1), null, true);
            $end = $next === false ? $closer : ($next - 1);
            for ($i = $opener; $i <= $end; $i++) {
                $phpcsFile->fixer->replaceToken($i, '');
            }

            return;
        }

        // A blank line between the last line left and the end, which was there to separate the removed tags.
        $lastKept = max(array_keys($kept));
        for ($line = ($lastKept + 1); $line < $tokens[$closer]['line']; $line++) {
            $remove[$line] = true;
        }

        for ($i = ($opener + 1); $i < $closer; $i++) {
            if (isset($remove[$tokens[$i]['line']]) && $tokens[$i]['line'] !== $tokens[$opener]['line']) {
                $phpcsFile->fixer->replaceToken($i, '');
            }
        }
    }

    /**
     * Insert attributes before a declaration, each on a line of its own, after its doc comment.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $declaration The position of the `class` or `function` keyword.
     * @param list<string> $attributes The attributes' code.
     */
    private function insertAttributes(File $phpcsFile, int $declaration, array $attributes): void
    {
        $tokens = $phpcsFile->getTokens();
        $opener = (int) DocCommentHelper::findDocCommentOpenPointer($phpcsFile, $declaration);
        $start = $phpcsFile->findNext(T_WHITESPACE, ($tokens[$opener]['comment_closer'] + 1), null, true);
        if ($start === false) {
            return;
        }

        $existing = $this->findExistingAttributes($phpcsFile, $start, $declaration);
        $indent = str_repeat(' ', $tokens[$start]['column'] - 1);
        $content = '';
        foreach (array_unique($attributes) as $attribute) {
            if (!in_array(self::shortForm($attribute), $existing, true)) {
                $content .= "{$attribute}{$phpcsFile->eolChar}{$indent}";
            }
        }

        if ($content !== '') {
            $phpcsFile->fixer->addContentBefore($start, $content);
        }
    }

    /**
     * Find the attributes already on a declaration, each in its short form.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the declaration's first token.
     * @param int $declaration The position of the `class` or `function` keyword.
     * @return list<string> The attributes, each as its short name and arguments, without spaces.
     */
    private function findExistingAttributes(File $phpcsFile, int $start, int $declaration): array
    {
        $tokens = $phpcsFile->getTokens();
        $existing = [];
        for ($i = $start; $i < $declaration; $i++) {
            if ($tokens[$i]['code'] === T_ATTRIBUTE && isset($tokens[$i]['attribute_closer'])) {
                $closer = $tokens[$i]['attribute_closer'];
                $existing[] = self::shortForm($phpcsFile->getTokensAsString($i, ($closer - $i + 1)));
                $i = $closer;
            }
        }

        return $existing;
    }

    /**
     * Write an attribute as its short name and arguments, without spaces, to compare two.
     *
     * @param string $attribute The attribute's code.
     * @return string The short form.
     */
    private static function shortForm(string $attribute): string
    {
        $inner = preg_replace('/\s+/', '', substr($attribute, 2, -1)) ?? '';
        $parenthesis = strpos($inner, '(');
        $name = $parenthesis === false ? $inner : substr($inner, 0, $parenthesis);
        $arguments = $parenthesis === false ? '' : substr($inner, $parenthesis);
        $lastBackslash = strrpos($name, '\\');

        return ($lastBackslash === false ? $name : substr($name, ($lastBackslash + 1))) . $arguments;
    }

    /**
     * Rename the methods marked `@test` in the class's `#[Depends]` attributes.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $classPtr The position of the `class` keyword.
     * @param array<string, string> $renames The method's name => its new name.
     */
    private function renameDependsAttributes(File $phpcsFile, int $classPtr, array $renames): void
    {
        $tokens = $phpcsFile->getTokens();
        $closer = $tokens[$classPtr]['scope_closer'] ?? null;
        if (!is_int($closer)) {
            return;
        }

        for ($i = $classPtr; $i < $closer; $i++) {
            if ($tokens[$i]['code'] !== T_ATTRIBUTE || !isset($tokens[$i]['attribute_closer'])) {
                continue;
            }

            $attributeCloser = $tokens[$i]['attribute_closer'];
            for ($j = $i; $j < $attributeCloser; $j++) {
                $isName = in_array($tokens[$j]['code'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
                $shortName = $isName ? substr((string) strrchr("\\{$tokens[$j]['content']}", '\\'), 1) : '';
                $isDepends = in_array($shortName, self::DEPENDS_ON_METHOD, true);
                $argument = $isDepends ? $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($j + 2), null, true) : false;
                if ($argument === false || $tokens[$argument]['code'] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $method = substr($tokens[$argument]['content'], 1, -1);
                if (isset($renames[$method])) {
                    $phpcsFile->fixer->replaceToken($argument, self::quote($renames[$method]));
                }
            }

            $i = $attributeCloser;
        }
    }

    /**
     * Report a hook method declared `public`, which `TestCase` declares `protected`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $method The position of the `function` keyword.
     */
    private function processHookVisibility(File $phpcsFile, int $method): void
    {
        $name = (string) $phpcsFile->getDeclarationName($method);
        if (!in_array(strtolower($name), self::HOOKS, true)) {
            return;
        }

        $properties = $phpcsFile->getMethodProperties($method);
        if ($properties['scope'] !== 'public' || $properties['scope_specified'] !== true) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $public = $phpcsFile->findPrevious(T_PUBLIC, ($method - 1));
        if ($public === false || $tokens[$public]['line'] !== $tokens[$method]['line']) {
            return;
        }

        $error = '%s() must be protected, as PHPUnit\'s TestCase declares it';
        if ($phpcsFile->addFixableError($error, $public, self::CODE_HOOK_VISIBILITY, [$name])) {
            $phpcsFile->fixer->replaceToken($public, 'protected');
        }
    }
}
