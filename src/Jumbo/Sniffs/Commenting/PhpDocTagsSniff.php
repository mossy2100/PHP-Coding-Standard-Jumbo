<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\Commenting;

use Jumbo\Helpers\DocTypeMatchHelper;
use PERCS31\Helpers\PropertyHookHelper;
use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\Constants;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypelessParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use SlevomatCodingStandard\Helpers\PhpDocParserHelper;
use SlevomatCodingStandard\Helpers\UseStatement;
use SlevomatCodingStandard\Helpers\UseStatementHelper;
use Throwable;

/**
 * Checks the tags in a docblock: their names, the format of inline tags, and the `@param` and `@return` tags of a
 * function.
 *
 * PER Coding Style 3.1 has no rule for docblocks, so this is an extra rule. Each check has its own code:
 *
 * - Casing: a known tag name in its usual case, such as `return` for `Return`. The names are the ones
 *   SlevomatCodingStandard.Commenting.AnnotationName knows, so `inheritDoc` is written in camel case.
 * - Alias: an alias replaced by its main tag, in any case: `inheritDocs` by `inheritDoc`, `returns` by `return`, `type`
 *   and `const` by `var`, and `link` by `see`.
 * - InlineFormat: an inline tag written as `{@name description}`, with the `@` inside a single brace at each end, and
 *   no space after the opening brace or before the closing one. Only the tags that can be inline are checked.
 *
 * - The `@param` and `@return` tags of a function or method, each read by its main name, so `@returns` is checked as
 *   `@return`:
 *   - UnknownParam and DuplicateParam: a `@param` whose name matches no parameter, or repeats one. Not fixed, as the
 *     description may be worth keeping, or the parameter may be what's missing.
 *   - ParamTypeMismatch and ReturnTypeMismatch: a type that doesn't match the native type, allowing the more specific
 *     PHPStan types, such as `list<int>` for `array`. A class, template or alias can't be compared without loading
 *     code, so a type with one isn't reported. Not fixed.
 *   - MissingType: a `@param` or `@return` without a type, as in `@param $x The value.` Not fixed.
 *   - Spacing: more than one space, or none, between the tag, its type, the variable and the description. The
 *     description itself isn't touched.
 *   - SelfReference: `this` or `@this` in a `@return`, fixed to `$this`, and `$self` or `@self`, fixed to `self`.
 * - A `@var` tag, on a property, a constant or an inline variable:
 *   - VarTypeMismatch: a property's or typed constant's type that doesn't match the native type, as above. Not fixed.
 *   - MissingType and Spacing, as above, one-line doc comments included: `/**@var int*\/` becomes `/** @var int *\/`.
 *   - VarName: a variable name in a property's or constant's `@var`, which is redundant, and removed. An inline `@var`
 *     keeps its name.
 *   - VarNameOrder: an inline `@var` with the name before the type, as in `@var $a int`, reordered.
 *   - VarInBlockComment: an inline `@var` in a `/* *\/` comment, which tools ignore, made a doc comment.
 * - Where the tags are. No tag has to be in any place, except these, none of them fixed:
 *   - ParamOrder: the `@param` tags in the order of the parameters.
 *   - ParamsNotTogether: the `@param` tags next to each other, with no other tag or blank line between them.
 *   - ReturnNotAfterParams: the `@return` tag after the `@param` tags.
 *   - ThrowsNotAfterReturn: each `@throws` tag after the `@return` and `@param` tags.
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
class PhpDocTagsSniff implements Sniff
{
    public const string CODE_CASING = 'Casing';

    public const string CODE_ALIAS = 'Alias';

    public const string CODE_INLINE_FORMAT = 'InlineFormat';

    public const string CODE_UNKNOWN_PARAM = 'UnknownParam';

    public const string CODE_DUPLICATE_PARAM = 'DuplicateParam';

    public const string CODE_PARAM_TYPE_MISMATCH = 'ParamTypeMismatch';

    public const string CODE_RETURN_TYPE_MISMATCH = 'ReturnTypeMismatch';

    public const string CODE_MISSING_TYPE = 'MissingType';

    public const string CODE_SPACING = 'Spacing';

    public const string CODE_SELF_REFERENCE = 'SelfReference';

    public const string CODE_VAR_TYPE_MISMATCH = 'VarTypeMismatch';

    public const string CODE_VAR_NAME = 'VarName';

    public const string CODE_VAR_NAME_ORDER = 'VarNameOrder';

    public const string CODE_VAR_IN_BLOCK_COMMENT = 'VarInBlockComment';

    public const string CODE_PARAM_ORDER = 'ParamOrder';

    public const string CODE_PARAMS_NOT_TOGETHER = 'ParamsNotTogether';

    public const string CODE_RETURN_NOT_AFTER_PARAMS = 'ReturnNotAfterParams';

    public const string CODE_THROWS_NOT_AFTER_RETURN = 'ThrowsNotAfterReturn';

    /**
     * The malformed self-references in a `@return`, with their fixes.
     *
     * @var array<string, string>
     */
    private const array SELF_REFERENCES = ['this' => '$this', '@this' => '$this', '$self' => 'self', '@self' => 'self'];

    /**
     * Aliases, in lower case, and the main tag that replaces each one.
     */
    private const array ALIASES
        = ['const' => 'var', 'inheritdocs' => 'inheritDoc', 'link' => 'see', 'returns' => 'return', 'type' => 'var'];

    /**
     * The tags whose inline format is checked. A longer name comes before a shorter one it starts with.
     */
    private const array INLINE_TAGS
        = ['example', 'id', 'inheritdocs', 'inheritdoc', 'internal', 'link', 'see', 'source', 'toc', 'tutorial'];

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
        return [T_DOC_COMMENT_OPEN_TAG, T_COMMENT];
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
        if ($tokens[$stackPtr]['code'] === T_COMMENT) {
            self::processBlockComment($phpcsFile, $stackPtr);
            return;
        }

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

        $function = self::findFunction($phpcsFile, $stackPtr);
        if ($function !== null) {
            self::processFunctionTags($phpcsFile, $stackPtr, $function);
        }

        self::processVarTags($phpcsFile, $stackPtr);
        self::checkTagOrder($phpcsFile, $tokens[$stackPtr]['comment_tags']);
    }

    /**
     * Report an inline `@var` in a `/* *\/` comment, which tools ignore, and make it a doc comment.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the comment.
     */
    private static function processBlockComment(File $phpcsFile, int $stackPtr): void
    {
        $content = $phpcsFile->getTokens()[$stackPtr]['content'];
        if (preg_match('~^/\*(?!\*)\s*@var\s~', $content) !== 1 || !str_contains($content, '*/')) {
            return;
        }

        $error = 'An inline @var must be in a doc comment, /** */, which tools read';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_VAR_IN_BLOCK_COMMENT)) {
            $phpcsFile->fixer->replaceToken($stackPtr, '/**' . substr($content, 2));
        }
    }

    /**
     * Check the `@var` tags in a docblock.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the docblock's `/**`.
     */
    private static function processVarTags(File $phpcsFile, int $opener): void
    {
        $tokens = $phpcsFile->getTokens();
        [$nativeType, $isDeclaration] = self::findVarTarget($phpcsFile, $opener);
        foreach ($tokens[$opener]['comment_tags'] as $tag) {
            $lower = strtolower(substr($tokens[$tag]['content'], 1));
            if ((self::ALIASES[$lower] ?? $lower) !== 'var') {
                continue;
            }

            $stringPtr = $tag + 2;
            $hasValue = ($tokens[$stringPtr]['code'] ?? null) === T_DOC_COMMENT_STRING
                && $tokens[$stringPtr]['line'] === $tokens[$tag]['line'];
            [$value, $trailing] = $hasValue ? self::splitValue($phpcsFile, $stringPtr) : ['', ''];
            if (trim($value) === '') {
                $phpcsFile->addError('The @%s tag must have a type', $tag, self::CODE_MISSING_TYPE, ['var']);
                continue;
            }

            if (!$isDeclaration && preg_match('/^(&?\$\w+)\s+(\S+)(.*)$/s', $value, $match) === 1) {
                $error = 'An inline @var must have its type before the variable; found "%s"';
                if ($phpcsFile->addFixableError($error, $tag, self::CODE_VAR_NAME_ORDER, [$value])) {
                    $phpcsFile->fixer->replaceToken($stringPtr, "{$match[2]} {$match[1]}{$match[3]}{$trailing}");
                }

                continue;
            }

            $lexTokens = PhpDocParserHelper::getLexer()->tokenize($value);
            $node = PhpDocParserHelper::getParser()->parseTagValue(new TokenIterator($lexTokens), '@var');
            if (!$node instanceof VarTagValueNode) {
                continue;
            }

            if ($nativeType !== '') {
                self::checkType($phpcsFile, $tag, $node->type, $nativeType, self::CODE_VAR_TYPE_MISMATCH);
            }

            self::checkVarLayout($phpcsFile, $tag, $stringPtr, $node, $lexTokens, $isDeclaration);
        }

        self::checkOneLineSpacing($phpcsFile, $opener);
    }

    /**
     * Find what a docblock's `@var` is for: a property's or constant's declaration, or an inline variable.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the docblock's `/**`.
     * @return array{string, bool} The declaration's native type, if any, and whether it's a property or constant.
     */
    private static function findVarTarget(File $phpcsFile, int $opener): array
    {
        $tokens = $phpcsFile->getTokens();
        $conditions = $tokens[$opener]['conditions'];
        $scope = $conditions === [] ? null : $conditions[array_key_last($conditions)];
        $targets = [T_CONST, T_VARIABLE, T_SEMICOLON, T_OPEN_CURLY_BRACKET, T_FUNCTION];
        $target = $phpcsFile->findNext($targets, ($tokens[$opener]['comment_closer'] + 1));
        if ($target === false || $scope === null || !isset(Tokens::OO_SCOPE_TOKENS[$scope])) {
            return ['', false];
        }

        try {
            if ($tokens[$target]['code'] === T_CONST) {
                return [(string) Constants::getProperties($phpcsFile, $target)['type'], true];
            }

            if (
                $tokens[$target]['code'] === T_VARIABLE && PropertyHookHelper::isPropertyVariable($phpcsFile, $target)
            ) {
                return [$phpcsFile->getMemberProperties($target)['type'], true];
            }
        } catch (Throwable) {
            return ['', false];
        }

        return ['', false];
    }

    /**
     * Report a `@var` without exactly one space between its parts, or with a redundant variable name on a declaration,
     * and fix both at once.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $tag The position of the tag.
     * @param int $stringPtr The position of the tag's value.
     * @param VarTagValueNode $node The value, as parsed.
     * @param list<array{string, int, int}> $lexTokens The value's tokens.
     * @param bool $isDeclaration Whether it's on a property or constant.
     */
    private static function checkVarLayout(
        File $phpcsFile,
        int $tag,
        int $stringPtr,
        VarTagValueNode $node,
        array $lexTokens,
        bool $isDeclaration,
    ): void {
        $tokens = $phpcsFile->getTokens();
        [$value, $trailing] = self::splitValue($phpcsFile, $stringPtr);
        $type = self::typeSource($node->type, $lexTokens);
        if ($type === null || !str_starts_with($value, $type)) {
            return;
        }

        $rest = substr($value, strlen($type));
        if (preg_match('/^(?:\s+(&?(?:\.\.\.)?\$\w+))?(?:\s+(.*))?$/s', $rest, $match) !== 1) {
            return;
        }

        $variable = $match[1] ?? '';
        $hasName = $variable !== '' && $isDeclaration;
        if ($hasName) {
            $error = 'The variable name in a property\'s or constant\'s @var is redundant; found "%s"';
            $fix = $phpcsFile->addFixableError($error, $tag, self::CODE_VAR_NAME, [$variable]);
            $variable = '';
        }

        $parts = array_filter([$type, $variable, $match[2] ?? ''], static fn(string $part): bool => $part !== '');
        $expected = implode(' ', $parts);
        $gap = $tokens[($tag + 1)]['content'];
        $isSpaced = ($expected !== $value && !$hasName) || $gap !== ' ';
        if ($isSpaced) {
            $error = 'Expected one space between the parts of a @var tag';
            $fix = $phpcsFile->addFixableError($error, $tag, self::CODE_SPACING, []);
        }

        if (($hasName || $isSpaced) && ($fix ?? false)) {
            $phpcsFile->fixer->beginChangeset();
            $phpcsFile->fixer->replaceToken(($tag + 1), ' ');
            $phpcsFile->fixer->replaceToken($stringPtr, "{$expected}{$trailing}");
            $phpcsFile->fixer->endChangeset();
        }
    }

    /**
     * Report a one-line doc comment without one space after its `/**` and before its `*\/`, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the docblock's `/**`.
     */
    private static function checkOneLineSpacing(File $phpcsFile, int $opener): void
    {
        $tokens = $phpcsFile->getTokens();
        $closer = $tokens[$opener]['comment_closer'];
        if ($tokens[$opener]['line'] !== $tokens[$closer]['line'] || ($closer - $opener) < 2) {
            return;
        }

        $after = $tokens[($opener + 1)];
        $before = $tokens[($closer - 1)];
        $isAfterOk = $after['code'] === T_DOC_COMMENT_WHITESPACE && $after['content'] === ' ';
        $isBeforeOk = $before['code'] === T_DOC_COMMENT_WHITESPACE
            ? $before['content'] === ' '
            : str_ends_with($before['content'], ' ') && !str_ends_with($before['content'], '  ');
        if ($isAfterOk && $isBeforeOk) {
            return;
        }

        $error = 'Expected one space after the /** and before the */ of a one-line doc comment';
        if (!$phpcsFile->addFixableError($error, $opener, self::CODE_SPACING)) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        if (!$isAfterOk) {
            $after['code'] === T_DOC_COMMENT_WHITESPACE
                ? $phpcsFile->fixer->replaceToken(($opener + 1), ' ')
                : $phpcsFile->fixer->addContent($opener, ' ');
        }

        if (!$isBeforeOk) {
            $phpcsFile
                ->fixer
                ->replaceToken(
                    ($closer - 1),
                    $before['code'] === T_DOC_COMMENT_WHITESPACE ? ' ' : rtrim($before['content']) . ' ',
                );
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Split a tag's value from the space before a one-line doc comment's `*\/`, which PHP_CodeSniffer keeps in it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stringPtr The position of the value.
     * @return array{string, string} The value, and the space after it, which is kept as it is.
     */
    private static function splitValue(File $phpcsFile, int $stringPtr): array
    {
        $tokens = $phpcsFile->getTokens();
        $content = $tokens[$stringPtr]['content'];
        $next = $tokens[($stringPtr + 1)] ?? null;
        if ($next === null || $next['code'] !== T_DOC_COMMENT_CLOSE_TAG) {
            return [$content, ''];
        }

        $value = rtrim($content);

        return [$value, substr($content, strlen($value))];
    }

    /**
     * Get a doc type's code as written in a tag's value.
     *
     * @param TypeNode $type The type.
     * @param list<array{string, int, int}> $lexTokens The value's tokens.
     * @return ?string The code, or null if the parser gave no position.
     */
    private static function typeSource(TypeNode $type, array $lexTokens): ?string
    {
        $start = $type->getAttribute(Attribute::START_INDEX);
        $end = $type->getAttribute(Attribute::END_INDEX);
        if (!is_int($start) || !is_int($end)) {
            return null;
        }

        return implode(
            '',
            array_map(
                static fn(array $token): string => $token[Lexer::VALUE_OFFSET],
                array_slice($lexTokens, $start, ($end - $start + 1)),
            ),
        );
    }

    /**
     * Find the function or method a docblock is for.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the docblock's `/**`.
     * @return ?int The position of the `function` keyword, or null if the docblock isn't for one.
     */
    private static function findFunction(File $phpcsFile, int $opener): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $skip = [T_WHITESPACE, T_STATIC, T_FINAL, T_ABSTRACT, ...array_keys(Tokens::SCOPE_MODIFIERS)];
        $closer = $tokens[$opener]['comment_closer'];
        if (!is_int($closer)) {
            return null;
        }

        for ($i = ($closer + 1); isset($tokens[$i]); $i++) {
            $attributeCloser = $tokens[$i]['code'] === T_ATTRIBUTE ? ($tokens[$i]['attribute_closer'] ?? null) : null;
            if (is_int($attributeCloser)) {
                $i = $attributeCloser;
                continue;
            }

            if (!in_array($tokens[$i]['code'], $skip, true)) {
                return $tokens[$i]['code'] === T_FUNCTION ? $i : null;
            }
        }

        return null;
    }

    /**
     * Check a function's `@param` and `@return` tags.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $opener The position of the docblock's `/**`.
     * @param int $function The position of the `function` keyword.
     */
    private static function processFunctionTags(File $phpcsFile, int $opener, int $function): void
    {
        try {
            $parameters = [];
            foreach ($phpcsFile->getMethodParameters($function) as $parameter) {
                $parameters[$parameter['name']] = $parameter;
            }

            $returnType = $phpcsFile->getMethodProperties($function)['return_type'];
        } catch (RuntimeException) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $seen = [];
        foreach ($tokens[$opener]['comment_tags'] as $tag) {
            $lower = strtolower(substr($tokens[$tag]['content'], 1));
            $name = self::ALIASES[$lower] ?? $lower;
            if ($name !== 'param' && $name !== 'return') {
                continue;
            }

            $stringPtr = $tag + 2;
            $hasValue = ($tokens[$stringPtr]['code'] ?? null) === T_DOC_COMMENT_STRING
                && $tokens[$stringPtr]['line'] === $tokens[$tag]['line'];
            $value = $hasValue ? self::splitValue($phpcsFile, $stringPtr)[0] : '';
            if ($name === 'return' && $hasValue && self::checkSelfReference($phpcsFile, $stringPtr, $value)) {
                continue;
            }

            if (trim($value) === '') {
                $phpcsFile->addError('The @%s tag must have a type', $tag, self::CODE_MISSING_TYPE, [$name]);
                continue;
            }

            $lexTokens = PhpDocParserHelper::getLexer()->tokenize($value);
            $node = PhpDocParserHelper::getParser()->parseTagValue(new TokenIterator($lexTokens), "@{$name}");
            if ($node instanceof TypelessParamTagValueNode) {
                $phpcsFile->addError('The @%s tag must have a type', $tag, self::CODE_MISSING_TYPE, [$name]);
            }

            if ($node instanceof ParamTagValueNode || $node instanceof TypelessParamTagValueNode) {
                self::checkParamName($phpcsFile, $tag, $node->parameterName, $parameters, $seen);
            }

            if ($node instanceof ParamTagValueNode && isset($parameters[$node->parameterName])) {
                $parameter = $parameters[$node->parameterName];
                if (!$node->isVariadic && $parameter['variable_length'] !== true && $parameter['type_hint'] !== '') {
                    self::checkType(
                        $phpcsFile,
                        $tag,
                        $node->type,
                        $parameter['type_hint'],
                        self::CODE_PARAM_TYPE_MISMATCH,
                    );
                }
            }

            if ($node instanceof ReturnTagValueNode && $returnType !== '') {
                self::checkType($phpcsFile, $tag, $node->type, $returnType, self::CODE_RETURN_TYPE_MISMATCH);
            }

            if ($hasValue && ($node instanceof ParamTagValueNode || $node instanceof ReturnTagValueNode)) {
                self::checkSpacing($phpcsFile, $tag, $stringPtr, $node, $lexTokens);
            }
        }
    }

    /**
     * Report a `@return` whose type is a malformed self-reference, such as `this` for `$this`, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stringPtr The position of the tag's value.
     * @param string $value The value.
     * @return bool True if it was reported.
     */
    private static function checkSelfReference(File $phpcsFile, int $stringPtr, string $value): bool
    {
        if (preg_match('/^(@?this|[@$]self)(?=\s|$)/', $value, $match) !== 1) {
            return false;
        }

        $expected = self::SELF_REFERENCES[$match[1]];
        $error = 'Use "%s", not "%s", in a @return tag';
        if ($phpcsFile->addFixableError($error, $stringPtr, self::CODE_SELF_REFERENCE, [$expected, $match[1]])) {
            $content = $phpcsFile->getTokens()[$stringPtr]['content'];
            $phpcsFile->fixer->replaceToken($stringPtr, $expected . substr($content, strlen($match[1])));
        }

        return true;
    }

    /**
     * Report a `@param` whose name matches no parameter, one already documented, or one after a later parameter's.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $tag The position of the tag.
     * @param string $variable The parameter's name in the tag, with its `$`.
     * @param array<string, mixed> $parameters The function's parameters, by name.
     * @param array<string, int> $seen The parameters already documented, with their positions.
     */
    private static function checkParamName(
        File $phpcsFile,
        int $tag,
        string $variable,
        array $parameters,
        array &$seen,
    ): void {
        if (!isset($parameters[$variable])) {
            $error = 'The @param tag for %s matches no parameter';
            $phpcsFile->addError($error, $tag, self::CODE_UNKNOWN_PARAM, [$variable]);
            return;
        }

        if (isset($seen[$variable])) {
            $phpcsFile->addError(
                'The @param tag for %s repeats one before it',
                $tag,
                self::CODE_DUPLICATE_PARAM,
                [$variable],
            );
            return;
        }

        $position = (int) array_search($variable, array_keys($parameters), true);
        $previous = array_key_last($seen);
        if ($previous !== null && $position < $seen[$previous]) {
            $error = 'The @param tags must be in the order of the parameters; found %s after %s';
            $phpcsFile->addError($error, $tag, self::CODE_PARAM_ORDER, [$variable, $previous]);
        }

        $seen[$variable] = $position;
    }

    /**
     * Report a doc type that doesn't match the native type.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $tag The position of the tag.
     * @param TypeNode $docType The doc type.
     * @param string $nativeType The native type.
     * @param string $code The error code.
     */
    private static function checkType(
        File $phpcsFile,
        int $tag,
        TypeNode $docType,
        string $nativeType,
        string $code,
    ): void {
        if (DocTypeMatchHelper::matches($docType, $nativeType) !== false) {
            return;
        }

        $error = 'The type "%s" doesn\'t match the native type "%s"';
        $phpcsFile->addError($error, $tag, $code, [PhpDocParserHelper::getPrinter()->print($docType), $nativeType]);
    }

    /**
     * Report a `@param` or `@return` without exactly one space between its parts, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $tag The position of the tag.
     * @param int $stringPtr The position of the tag's value.
     * @param ParamTagValueNode|ReturnTagValueNode $node The value, as parsed.
     * @param list<array{string, int, int}> $lexTokens The value's tokens.
     */
    private static function checkSpacing(
        File $phpcsFile,
        int $tag,
        int $stringPtr,
        PhpDocTagValueNode $node,
        array $lexTokens,
    ): void {
        $tokens = $phpcsFile->getTokens();
        [$value, $trailing] = self::splitValue($phpcsFile, $stringPtr);
        $type = self::typeSource($node->type, $lexTokens);
        if ($type === null || !str_starts_with($value, $type)) {
            return;
        }

        $rest = substr($value, strlen($type));
        $pattern
            = $node instanceof ParamTagValueNode ? '/^\s+(&?(?:\.\.\.)?\$\S+)(?:\s+(.*))?$/s' : '/^()(?:\s+(.*))?$/s';
        if (preg_match($pattern, $rest, $match) !== 1) {
            return;
        }

        $parts = array_filter([$type, $match[1], $match[2] ?? ''], static fn(string $part): bool => $part !== '');
        $expected = implode(' ', $parts);
        $gap = $tokens[($tag + 1)]['content'];
        if ($expected === $value && $gap === ' ') {
            return;
        }

        $error = 'Expected one space between the parts of a @%s tag';
        $name = $node instanceof ParamTagValueNode ? 'param' : 'return';
        if ($phpcsFile->addFixableError($error, $tag, self::CODE_SPACING, [$name])) {
            $phpcsFile->fixer->beginChangeset();
            $phpcsFile->fixer->replaceToken(($tag + 1), ' ');
            $phpcsFile->fixer->replaceToken($stringPtr, "{$expected}{$trailing}");
            $phpcsFile->fixer->endChangeset();
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

        $fixed = self::fixInlineFormat($phpcsFile, $first, $text);
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
    private static function fixInlineFormat(File $phpcsFile, int $stackPtr, string $text): string
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
        if ($expected === null || self::isImported($phpcsFile, $stackPtr, $name)) {
            return $text;
        }

        if (!self::reportName($phpcsFile, $stackPtr, $name, $expected)) {
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
            if ($expected === null || !self::reportName($phpcsFile, $stackPtr, $matches[1], $expected)) {
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
    private static function reportName(File $phpcsFile, int $stackPtr, string $name, string $expected): bool
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
    private static function isImported(File $phpcsFile, int $stackPtr, string $name): bool
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

    // region Tag layout

    /**
     * Report `@param` tags that aren't together, a `@return` before a `@param`, and a `@throws` before either.
     *
     * Every other tag can be anywhere.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array<int, int> $tags The positions of the tags.
     */
    private static function checkTagOrder(File $phpcsFile, array $tags): void
    {
        $tokens = $phpcsFile->getTokens();
        $names = [];
        foreach ($tags as $tag) {
            $lower = strtolower(substr($tokens[$tag]['content'], 1));
            $names[$tag] = self::ALIASES[$lower] ?? $lower;
        }

        $params = array_keys($names, 'param', true);
        $returns = array_keys($names, 'return', true);
        foreach (array_slice($params, 1) as $index => $param) {
            if (!self::isNextTo($phpcsFile, $params[$index], $param)) {
                $error = 'The @param tags must be together, with no other tag or blank line between them';
                $phpcsFile->addError($error, $param, self::CODE_PARAMS_NOT_TOGETHER);
                break;
            }
        }

        $lastParam = $params === [] ? -1 : max($params);
        foreach ($returns as $return) {
            if ($return < $lastParam) {
                $error = 'The @return tag must come after the @param tags';
                $phpcsFile->addError($error, $return, self::CODE_RETURN_NOT_AFTER_PARAMS);
            }
        }

        $lastBefore = max($lastParam, $returns === [] ? -1 : max($returns));
        foreach (array_keys($names, 'throws', true) as $throws) {
            if ($throws < $lastBefore) {
                $error = 'A @throws tag must come after the @param and @return tags';
                $phpcsFile->addError($error, $throws, self::CODE_THROWS_NOT_AFTER_RETURN);
            }
        }
    }

    /**
     * Check whether a tag comes straight after another, with no tag or blank line between them.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $before The position of the first tag.
     * @param int $tag The position of the second.
     * @return bool True if it does.
     */
    private static function isNextTo(File $phpcsFile, int $before, int $tag): bool
    {
        $tokens = $phpcsFile->getTokens();
        if ($phpcsFile->findNext(T_DOC_COMMENT_TAG, ($before + 1), $tag) !== false) {
            return false;
        }

        // The end of the first tag, which may run over several lines.
        $end = $phpcsFile->findPrevious(T_DOC_COMMENT_STRING, ($tag - 1), $before);

        return $tokens[$end === false ? $before : $end]['line'] === $tokens[$tag]['line'] - 1;
    }

    // endregion
}
