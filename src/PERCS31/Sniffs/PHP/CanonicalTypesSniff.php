<?php

declare(strict_types=1);

namespace PERCS31\Sniffs\PHP;

use PERCS31\Helpers\DocTypeHelper;
use PERCS31\Helpers\PropertyHookHelper;
use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\Constants;
use Throwable;

/**
 * Requires the canonical spelling of a type, wherever it's written: the short name, and PHP's own types in lower case.
 *
 * PER Coding Style 3.1, section 2.5, says "Short form of type keywords MUST be used i.e. `bool` instead of `boolean`,
 * `int` instead of `integer` etc.", and "All PHP reserved keywords and types MUST be in lower case." So:
 * - `boolean`, `integer`, `double` and `binary` are reported as LongName, and fixed to `bool`, `int`, `float` and
 *   `string`. PHP 8.5 deprecates all four as casts.
 * - PHP's own types written in another case, such as `INT`, `Bool` or `NULL`, are reported as NotLowerCase, and
 *   lowered. A class name is left alone.
 *
 * Both are checked in a cast, every native type declaration (a parameter, including a `set` hook's, a return type, a
 * property, a typed constant and a backed enum's type), and every type in a doc comment, including inside generics, as
 * section 2.5 isn't limited to native types.
 *
 * In a type declaration, `integer $x` declares a class named `integer`, so fixing it to `int` changes the code. PER
 * requires the short name, so the unqualified name is fixed; `\integer` is left alone, as it's deliberate.
 *
 * A type that PHP_CodeSniffer reads as a keyword, such as `SELF`, `STATIC` or `CALLABLE`, is left to
 * PERCS31.PHP.LowerCaseKeyword, which reports it.
 */
class CanonicalTypesSniff implements Sniff
{
    public const string CODE_LONG_NAME = 'LongName';

    public const string CODE_NOT_LOWER_CASE = 'NotLowerCase';

    /**
     * The long type names, with their short forms.
     *
     * @var array<string, string>
     */
    private const array LONG_NAMES = [
        'boolean' => 'bool',
        'integer' => 'int',
        'double' => 'float',
        'binary' => 'string',
    ];

    /**
     * PHP's types, in native declarations and casts.
     *
     * @var array<string, true>
     */
    private const array NATIVE_TYPES = [
        'array' => true,
        'bool' => true,
        'callable' => true,
        'false' => true,
        'float' => true,
        'int' => true,
        'iterable' => true,
        'mixed' => true,
        'never' => true,
        'null' => true,
        'object' => true,
        'parent' => true,
        'self' => true,
        'static' => true,
        'string' => true,
        'true' => true,
        'void' => true,
    ];

    /**
     * PHP's types in a doc comment: the native ones, and `resource` and `scalar`, as php-cs-fixer's `phpdoc_types` has.
     *
     * @var array<string, true>
     */
    private const array DOC_TYPES = [...self::NATIVE_TYPES, 'resource' => true, 'scalar' => true];

    /**
     * The tokens a type name in a declaration can be.
     *
     * @var list<int|string>
     */
    private const array NAME_TOKENS = [T_STRING, T_NULL, T_FALSE, T_TRUE, T_ARRAY];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [
            ...array_keys(Tokens::CAST_TOKENS),
            ...array_keys(Tokens::OO_SCOPE_TOKENS),
            T_FUNCTION,
            T_CLOSURE,
            T_FN,
            T_STRING,
            T_DOC_COMMENT_OPEN_TAG,
        ];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $code = $phpcsFile->getTokens()[$stackPtr]['code'];
        match (true) {
            isset(Tokens::CAST_TOKENS[$code]) => $this->processCast($phpcsFile, $stackPtr),
            isset(Tokens::OO_SCOPE_TOKENS[$code]) => $this->processClass($phpcsFile, $stackPtr),
            in_array($code, [T_FUNCTION, T_CLOSURE, T_FN], true) => $this->processFunction($phpcsFile, $stackPtr),
            $code === T_STRING => $this->processHook($phpcsFile, $stackPtr),
            default => $this->processDocComment($phpcsFile, $stackPtr),
        };
    }

    /**
     * Check a cast, such as `(INTEGER)`, which becomes `(int)`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the cast.
     */
    private function processCast(File $phpcsFile, int $stackPtr): void
    {
        // A binary string's prefix, as in b'abc', has the same token as the cast, so go by the content.
        $content = $phpcsFile->getTokens()[$stackPtr]['content'];
        if (preg_match('/^(\(\s*)([A-Za-z]+)(\s*\))$/', $content, $match) !== 1) {
            return;
        }

        $expected = self::canonical($match[2], self::NATIVE_TYPES);
        if ($expected === null) {
            return;
        }

        if ($this->report($phpcsFile, $stackPtr, $match[2], $expected, 'a cast')) {
            $phpcsFile->fixer->replaceToken($stackPtr, "{$match[1]}{$expected}{$match[3]}");
        }
    }

    /**
     * Check a function's, closure's or arrow function's return type and parameter types.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `function` or `fn` keyword.
     */
    private function processFunction(File $phpcsFile, int $stackPtr): void
    {
        try {
            $properties = $phpcsFile->getMethodProperties($stackPtr);
            $parameters = $phpcsFile->getMethodParameters($stackPtr);
        } catch (RuntimeException) {
            return;
        }

        if ($properties['return_type_token'] !== false && $properties['return_type_end_token'] !== false) {
            $start = $properties['return_type_token'];
            $this->processRange($phpcsFile, $start, $properties['return_type_end_token'], 'a return type');
        }

        foreach ($parameters as $parameter) {
            if ($parameter['type_hint_token'] !== false && $parameter['type_hint_end_token'] !== false) {
                $start = $parameter['type_hint_token'];
                $this->processRange($phpcsFile, $start, $parameter['type_hint_end_token'], 'a parameter type');
            }
        }
    }

    /**
     * Check the types in a class, interface, trait or enum: its constants', its properties', and an enum's backing
     * type.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the keyword.
     */
    private function processClass(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if ($tokens[$stackPtr]['code'] === T_ENUM) {
            $this->processBackingType($phpcsFile, $stackPtr);
        }

        $opener = $tokens[$stackPtr]['scope_opener'] ?? null;
        $closer = $tokens[$stackPtr]['scope_closer'] ?? null;
        if (!is_int($opener) || !is_int($closer)) {
            return;
        }

        for ($i = ($opener + 1); $i < $closer; $i++) {
            $code = $tokens[$i]['code'];
            if ($code === T_DOC_COMMENT_OPEN_TAG || $code === T_FUNCTION) {
                $i = $tokens[$i]['comment_closer'] ?? $tokens[$i]['scope_closer'] ?? $i;
                continue;
            }

            if ($code === T_CONST) {
                $this->processConstant($phpcsFile, $i);
            } elseif ($code === T_VARIABLE && PropertyHookHelper::isPropertyVariable($phpcsFile, $i)) {
                $this->processProperty($phpcsFile, $i);
            }
        }
    }

    /**
     * Check a typed class constant's type.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the `const` keyword.
     */
    private function processConstant(File $phpcsFile, int $stackPtr): void
    {
        try {
            $properties = Constants::getProperties($phpcsFile, $stackPtr);
        } catch (Throwable) {
            return;
        }

        $start = $properties['type_token'];
        $end = $properties['type_end_token'];
        if (is_int($start) && is_int($end)) {
            $this->processRange($phpcsFile, $start, $end, 'a constant type');
        }
    }

    /**
     * Check a property's type.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the variable.
     */
    private function processProperty(File $phpcsFile, int $stackPtr): void
    {
        try {
            $properties = $phpcsFile->getMemberProperties($stackPtr);
        } catch (RuntimeException) {
            return;
        }

        if ($properties['type_token'] !== false && $properties['type_end_token'] !== false) {
            $start = $properties['type_token'];
            $this->processRange($phpcsFile, $start, $properties['type_end_token'], 'a property type');
        }
    }

    /**
     * Check a backed enum's type, as in `enum Suit: STRING`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $enumPtr The position of the `enum` keyword.
     */
    private function processBackingType(File $phpcsFile, int $enumPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $name = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($enumPtr + 1), null, true);
        $colon = $name === false ? false : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($name + 1), null, true);
        if ($colon === false || $tokens[$colon]['code'] !== T_COLON) {
            return;
        }

        $type = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($colon + 1), null, true);
        if ($type !== false) {
            $this->processRange($phpcsFile, $type, $type, 'an enum\'s backing type');
        }
    }

    /**
     * Check a `set` hook's parameter type, as in `set(INT $value)`, which PHP_CodeSniffer reads as an expression.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of a name, which may be the hook's.
     */
    private function processHook(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        if (strtolower($tokens[$stackPtr]['content']) !== 'set') {
            return;
        }

        $opener = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        if ($opener === false || !PropertyHookHelper::isHookParameterList($phpcsFile, $opener)) {
            return;
        }

        $closer = $tokens[$opener]['parenthesis_closer'];
        $variable = $phpcsFile->findNext(T_VARIABLE, ($opener + 1), $closer);
        if ($variable !== false) {
            $this->processRange($phpcsFile, ($opener + 1), ($variable - 1), 'a parameter type');
        }
    }

    /**
     * Check the type names in a range of a type declaration.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the range's first token.
     * @param int $end The position of its last token.
     * @param string $place Where the type is, for the error message.
     */
    private function processRange(File $phpcsFile, int $start, int $end, string $place): void
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $start; $i <= $end; $i++) {
            // An attribute's arguments are expressions.
            $i = $tokens[$i]['attribute_closer'] ?? $i;
            if (!in_array($tokens[$i]['code'], self::NAME_TOKENS, true)) {
                continue;
            }

            $name = $tokens[$i]['content'];
            $expected = self::canonical($name, self::NATIVE_TYPES);
            if ($expected !== null && $this->report($phpcsFile, $i, $name, $expected, $place)) {
                $phpcsFile->fixer->replaceToken($i, $expected);
            }
        }
    }

    /**
     * Check the types in a doc comment.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the doc comment's `/**`.
     */
    private function processDocComment(File $phpcsFile, int $stackPtr): void
    {
        $fix = false;
        foreach (
            DocTypeHelper::findIdentifiers($phpcsFile, $stackPtr)
            as ['annotation' => $annotation, 'node' => $node]
        ) {
            $expected = self::canonical($node->name, self::DOC_TYPES);
            if ($expected !== null) {
                $place = "a {$annotation->getName()} tag";
                $fix = $this->report(
                    $phpcsFile,
                    $annotation->getStartPointer(),
                    $node->name,
                    $expected,
                    $place,
                ) || $fix;
            }
        }

        if (!$fix) {
            return;
        }

        $content = DocTypeHelper::renameIdentifiers(
            $phpcsFile,
            $stackPtr,
            static fn(string $name): string => self::canonical($name, self::DOC_TYPES) ?? $name,
        );
        if ($content !== null) {
            DocTypeHelper::replace($phpcsFile, $stackPtr, $content);
        }
    }

    /**
     * Get the canonical spelling of a type name, if it isn't already.
     *
     * @param string $name The name, as written.
     * @param array<string, true> $types PHP's types, which are lowered.
     * @return string|null The canonical spelling, or null if the name is already canonical, or isn't PHP's.
     */
    private static function canonical(string $name, array $types): ?string
    {
        $lower = strtolower($name);
        $expected = self::LONG_NAMES[$lower] ?? (isset($types[$lower]) ? $lower : null);

        return $expected === $name ? null : $expected;
    }

    /**
     * Report a type name that isn't canonical.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position to report at.
     * @param string $name The name, as written.
     * @param string $expected Its canonical spelling.
     * @param string $place Where the type is, for the message.
     * @return bool True if it's to be fixed now.
     */
    private function report(File $phpcsFile, int $stackPtr, string $name, string $expected, string $place): bool
    {
        if (isset(self::LONG_NAMES[strtolower($name)])) {
            $error = 'Use the short type name "%s", not "%s", in %s';

            return $phpcsFile->addFixableError($error, $stackPtr, self::CODE_LONG_NAME, [$expected, $name, $place]);
        }

        $error = 'PHP types must be in lower case; expected "%s" but found "%s" in %s';

        return $phpcsFile->addFixableError($error, $stackPtr, self::CODE_NOT_LOWER_CASE, [$expected, $name, $place]);
    }
}
