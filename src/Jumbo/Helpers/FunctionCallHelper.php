<?php

declare(strict_types=1);

namespace Jumbo\Helpers;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\PassedParameters;

/**
 * Helper class for calls to PHP's global functions: which function a name calls, its arguments, and whether the call
 * is a statement of its own, with its return value unused.
 */
class FunctionCallHelper
{
    /**
     * The tokens that, before a name, show it isn't a call to a global function.
     *
     * @var list<int|string>
     */
    private const array NOT_A_CALL_AFTER = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_NEW,
        T_FUNCTION,
        T_FN,
        T_CONST,
        T_USE,
        T_NS_SEPARATOR,
        T_GOTO,
    ];

    /**
     * The tokens a simple operand is made of, outside any brackets: a variable, a property or method chain, a call, a
     * name, a constant or a literal.
     *
     * @var list<int|string>
     */
    private const array SIMPLE_TOKENS = [
        T_VARIABLE,
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_CONSTANT_ENCAPSED_STRING,
        T_DOUBLE_QUOTED_STRING,
        T_LNUMBER,
        T_DNUMBER,
        T_TRUE,
        T_FALSE,
        T_NULL,
        T_SELF,
        T_STATIC,
        T_PARENT,
        T_CLASS,
        T_OPEN_PARENTHESIS,
        T_OPEN_SQUARE_BRACKET,
        T_OPEN_SHORT_ARRAY,
        T_OPEN_CURLY_BRACKET,
    ];

    /**
     * The functions each file declares, and the names its `use function` statements import, by file name.
     *
     * @var array<string, array{int, array<string, true>}>
     */
    private static array $fileNames = [];

    /**
     * Get the global function a name calls, resolved as PHP does: a fully qualified name, or an unqualified one that
     * no `use function` statement imports from elsewhere, and that the file doesn't declare a function by.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the name.
     * @return ?string The function's name, in lower case, or null if the name isn't a call to a global function.
     */
    public static function globalFunctionName(File $phpcsFile, int $stackPtr): ?string
    {
        $tokens = $phpcsFile->getTokens();
        $code = $tokens[$stackPtr]['code'];
        if (
            ($code !== T_STRING && $code !== T_NAME_FULLY_QUALIFIED) || isset($tokens[$stackPtr]['nested_attributes'])
        ) {
            return null;
        }

        $opener = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        if (
            $opener === false || $tokens[$opener]['code'] !== T_OPEN_PARENTHESIS
            || ($previous !== false && in_array($tokens[$previous]['code'], self::NOT_A_CALL_AFTER, true))
        ) {
            return null;
        }

        $name = strtolower(ltrim($tokens[$stackPtr]['content'], '\\'));
        if ($code === T_NAME_FULLY_QUALIFIED) {
            return str_contains($name, '\\') ? null : $name;
        }

        return isset(self::localNames($phpcsFile)[$name]) ? null : $name;
    }

    /**
     * Get a call's opening parenthesis.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @return int The position of the parenthesis.
     */
    public static function opener(File $phpcsFile, int $stackPtr): int
    {
        return (int) $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
    }

    /**
     * Get a call's closing parenthesis.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name, or a language construct such as `isset`.
     * @return int The position of the parenthesis.
     */
    public static function closer(File $phpcsFile, int $stackPtr): int
    {
        return (int) $phpcsFile->getTokens()[self::opener($phpcsFile, $stackPtr)]['parenthesis_closer'];
    }

    /**
     * Get a call's arguments, by position.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @return list<array{start: int, end: int, clean: string}>|null The arguments, or null if one is named or
     *     unpacked, or the call is a first-class callable, as in `strlen(...)`.
     */
    public static function arguments(File $phpcsFile, int $stackPtr): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $arguments = [];
        foreach (PassedParameters::getParameters($phpcsFile, $stackPtr) as $key => $argument) {
            $start = is_int($argument['start'])
                ? $phpcsFile->findNext(Tokens::EMPTY_TOKENS, $argument['start'], null, true)
                : false;
            $end = is_int($argument['end'])
                ? $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, $argument['end'], null, true)
                : false;
            if (!is_int($key) || $start === false || $end === false || $tokens[$start]['code'] === T_ELLIPSIS) {
                return null;
            }

            $arguments[] = ['start' => $start, 'end' => $end, 'clean' => trim((string) $argument['clean'])];
        }

        return $arguments;
    }

    /**
     * Get a string literal's value, if it's one simple string.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return ?string The value, or null if the expression isn't a single-quoted string, or a double-quoted one
     *     with no `\` or `$`.
     */
    public static function stringValue(File $phpcsFile, int $start, int $end): ?string
    {
        $tokens = $phpcsFile->getTokens();
        $content = $tokens[$start]['content'];
        if ($start !== $end || $tokens[$start]['code'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $body = substr($content, 1, -1);
        if ($content[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        return str_contains($body, '\\') || str_contains($body, '$') ? null : $body;
    }

    /**
     * Check whether an expression is a statement of its own, so its value isn't used.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of its first token.
     * @param int $end The position of its last token.
     * @return bool True if it is.
     */
    public static function isStatement(File $phpcsFile, int $start, int $end): bool
    {
        $tokens = $phpcsFile->getTokens();
        $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($start - 1), null, true);
        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($end + 1), null, true);
        $starts = [T_SEMICOLON, T_OPEN_CURLY_BRACKET, T_CLOSE_CURLY_BRACKET, T_OPEN_TAG];

        $isStart = $previous === false || in_array($tokens[$previous]['code'], $starts, true);

        return $isStart && $next !== false && in_array($tokens[$next]['code'], [T_SEMICOLON, T_CLOSE_TAG], true);
    }

    /**
     * Check whether an expression is a simple operand, which needs no parentheses beside an operator: a variable, a
     * property or method chain, a call, a name, a constant or a literal.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of its first token.
     * @param int $end The position of its last token.
     * @return bool True if it is.
     */
    public static function isSimpleOperand(File $phpcsFile, int $start, int $end): bool
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $start; $i <= $end; $i++) {
            $code = $tokens[$i]['code'];
            if ($code === T_WHITESPACE) {
                continue;
            }

            if (!in_array($code, self::SIMPLE_TOKENS, true)) {
                return false;
            }

            $closer = $tokens[$i]['parenthesis_closer'] ?? $tokens[$i]['bracket_closer'] ?? null;
            if (is_int($closer) && $closer > $i) {
                $i = $closer;
            }
        }

        return true;
    }

    /**
     * Get the lowest PHP version the project supports, from PHP_CodeSniffer's `php_version` setting, as in
     * `--runtime-set php_version 80500` or `<config name="php_version" value="80500"/>`.
     *
     * Without the setting, the version isn't known. The PHP running PHP_CodeSniffer may be newer than the project's
     * lowest, so it isn't assumed, and a rule that needs a newer PHP is skipped.
     *
     * @return ?int The version, as PHP_VERSION_ID gives it, such as 80400, or null if it isn't set.
     */
    public static function minimumPhpVersion(): ?int
    {
        $version = Config::getConfigData('php_version');

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * Get the functions a file declares outside a class, and the names its `use function` statements import, which an
     * unqualified call refers to instead of a global function.
     *
     * @param File $phpcsFile The file being scanned.
     * @return array<string, true> The names, in lower case.
     */
    private static function localNames(File $phpcsFile): array
    {
        $key = $phpcsFile->getFilename();
        $cached = self::$fileNames[$key] ?? null;
        if ($cached !== null && $cached[0] === $phpcsFile->numTokens) {
            return $cached[1];
        }

        $tokens = $phpcsFile->getTokens();
        $names = [];
        $hasNamespace = $phpcsFile->findNext(T_NAMESPACE, 0) !== false;
        foreach ($tokens as $ptr => $token) {
            if ($token['code'] === T_FUNCTION && $hasNamespace && !self::isInClass($token)) {
                $names[strtolower((string) $phpcsFile->getDeclarationName($ptr))] = true;
            } elseif ($token['code'] === T_USE && ($token['conditions'] ?? []) === []) {
                $names += self::importedFunctionNames($phpcsFile, $ptr);
            }
        }

        self::$fileNames[$key] = [$phpcsFile->numTokens, $names];

        return $names;
    }

    /**
     * Check whether a token is inside a class, interface, trait or enum.
     *
     * @param array<string, mixed> $token The token.
     * @return bool True if it is.
     */
    private static function isInClass(array $token): bool
    {
        $conditions = $token['conditions'] ?? [];
        foreach (is_array($conditions) ? $conditions : [] as $code) {
            if ((is_int($code) || is_string($code)) && isset(Tokens::OO_SCOPE_TOKENS[$code])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the names a `use function` statement imports, as they're called in the file.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $usePtr The position of the `use` keyword.
     * @return array<string, true> The names, in lower case.
     */
    private static function importedFunctionNames(File $phpcsFile, int $usePtr): array
    {
        $tokens = $phpcsFile->getTokens();
        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($usePtr + 1), null, true);
        $end = $phpcsFile->findNext(T_SEMICOLON, ($usePtr + 1));
        if ($next === false || $end === false || strtolower($tokens[$next]['content']) !== 'function') {
            return [];
        }

        $names = [];
        $imported = null;
        $alias = null;
        for ($i = ($next + 1); $i <= $end; $i++) {
            $code = $tokens[$i]['code'];
            if (in_array($code, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $imported === null ? $imported = ltrim($tokens[$i]['content'], '\\') : $alias = $tokens[$i]['content'];
            } elseif (in_array($code, [T_COMMA, T_SEMICOLON], true) && $imported !== null) {
                // A global function imported as itself, as in `use function strlen;`, is still the global one.
                $parts = explode('\\', $imported);
                $name = strtolower($alias ?? end($parts));
                if (count($parts) > 1 || $name !== strtolower($imported)) {
                    $names[$name] = true;
                }

                $imported = null;
                $alias = null;
            }
        }

        return $names;
    }
}
