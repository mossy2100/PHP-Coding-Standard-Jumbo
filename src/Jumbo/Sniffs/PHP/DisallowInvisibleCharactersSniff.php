<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PHP;

use IntlChar;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Disallows any invisible or non-printable character except a space and a line feed.
 *
 * Such a character can hide what code does: a bidirectional control can make code read differently from how it runs
 * ("Trojan Source"), and a zero-width or no-break space looks like nothing, or like a space. What counts, by Unicode
 * category: control characters (`Cc`) except a line feed, such as a tab, carriage return or NUL; format characters
 * (`Cf`), such as a zero-width space or joiner, a soft hyphen, a byte order mark and the bidirectional controls; and
 * separators (`Zs`, `Zl`, `Zp`) except a space, such as a no-break or em space. A byte order mark at the very start of
 * the file is left to Generic.Files.ByteOrderMark.
 *
 * The fix depends on where the character is:
 * - In a double-quoted string or a heredoc, it's rewritten as an escape (`\t`, `\r`, `\v`, `\f`, `\e`, `\0`, `\x1F`
 *   or `\u{00A0}`), which keeps the value and makes the character visible. One inside or straight after an
 *   interpolated variable isn't fixed, as the escape could change what's interpolated.
 * - A single-quoted string on one line, or a nowdoc, can't hold an escape, so it becomes a double-quoted string or a
 *   heredoc, if it has no `$` or `\` that would then mean something. Otherwise it's reported only.
 * - In code or a comment, it becomes a space, or is removed if it has no width (a format character). A tab becomes
 *   spaces to the next tab stop, as Generic.WhiteSpace.DisallowTabIndent's fix does, and a carriage return before a
 *   line feed is removed, as the line endings sniff's fix does.
 * - In a name, such as a variable's, or in inline HTML, it's reported only, as a fix would change the name or the
 *   output.
 *
 * Set allowTabs to allow a tab outside a string, for a project that indents with tabs. A tab in a string is always
 * reported, as it's invisible content in the value.
 */
class DisallowInvisibleCharactersSniff implements Sniff
{
    public const string CODE_INVISIBLE_CHARACTER = 'InvisibleCharacter';

    /**
     * The characters checked: control, format and separator characters, except a line feed and a space.
     */
    private const string PATTERN = '/(?![\n ])[\p{Cc}\p{Cf}\p{Z}]/u';

    /**
     * The control characters checked, for text that isn't valid UTF-8.
     */
    private const string BYTE_PATTERN = '/[\x00-\x09\x0B-\x1F\x7F]/';

    /**
     * The escapes for control characters that have a letter.
     *
     * @var array<string, string>
     */
    private const array LETTER_ESCAPES = ["\t" => '\t', "\r" => '\r', "\v" => '\v', "\f" => '\f', "\e" => '\e'];

    /**
     * The names of the control characters most often found, which have no Unicode name, only an alias.
     *
     * @var array<int, string>
     */
    private const array CONTROL_NAMES = [
        0x00 => 'NULL',
        0x09 => 'CHARACTER TABULATION',
        0x0B => 'LINE TABULATION',
        0x0C => 'FORM FEED',
        0x0D => 'CARRIAGE RETURN',
        0x1B => 'ESCAPE',
        0x7F => 'DELETE',
    ];

    /**
     * The tokens a comment is made of.
     *
     * @var list<int|string>
     */
    private const array COMMENT_TOKENS = [
        T_DOC_COMMENT_OPEN_TAG,
        T_DOC_COMMENT_CLOSE_TAG,
        T_DOC_COMMENT_STAR,
        T_DOC_COMMENT_STRING,
        T_DOC_COMMENT_TAG,
        T_DOC_COMMENT_WHITESPACE,
    ];

    /**
     * Whether a tab is allowed outside a string.
     */
    public bool $allowTabs = false;

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [
            ...self::COMMENT_TOKENS,
            ...array_keys(Tokens::PHPCS_ANNOTATION_TOKENS),
            T_OPEN_TAG,
            T_OPEN_TAG_WITH_ECHO,
            T_CLOSE_TAG,
            T_INLINE_HTML,
            T_WHITESPACE,
            T_COMMENT,
            T_CONSTANT_ENCAPSED_STRING,
            T_DOUBLE_QUOTED_STRING,
            T_HEREDOC,
            T_NOWDOC,
            T_STRING,
            T_VARIABLE,
            T_NAME_QUALIFIED,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
            T_BAD_CHARACTER,
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
        $token = $phpcsFile->getTokens()[$stackPtr];
        $content = $token['orig_content'] ?? $token['content'];
        $characters = self::findCharacters($content);
        if ($stackPtr === 0 && str_starts_with($content, "\u{FEFF}")) {
            unset($characters[0]);
        }

        if ($characters !== []) {
            $this->processToken($phpcsFile, $stackPtr, $content, $characters);
        }
    }

    /**
     * Find the characters checked in a token's content.
     *
     * @param string $content The content.
     * @return array<int, string> Each character, by its byte offset.
     */
    private static function findCharacters(string $content): array
    {
        $count = preg_match_all(self::PATTERN, $content, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            preg_match_all(self::BYTE_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE);
        }

        $characters = [];
        foreach ($matches[0] as [$character, $offset]) {
            $characters[$offset] = $character;
        }

        return $characters;
    }

    /**
     * Report the characters in a token, and fix those that can be.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @param string $content The token's content.
     * @param array<int, string> $characters Each character found, by its byte offset.
     */
    private function processToken(File $phpcsFile, int $stackPtr, string $content, array $characters): void
    {
        $code = $phpcsFile->getTokens()[$stackPtr]['code'];
        $isText = $code === T_WHITESPACE || $code === T_COMMENT || in_array($code, self::COMMENT_TOKENS, true)
            || isset(Tokens::PHPCS_ANNOTATION_TOKENS[$code]);
        if ($isText && $this->allowTabs) {
            $characters = array_filter($characters, static fn(string $character): bool => $character !== "\t");
        }

        [$fixed, $fixable] = match (true) {
            $isText => [$this->fixText($phpcsFile, $stackPtr, $content), array_keys($characters)],
            in_array($code, [T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_QUOTED_STRING, T_HEREDOC], true)
                => self::fixString($phpcsFile, $stackPtr, $content, $characters),
            $code === T_NOWDOC => self::fixNowdoc($phpcsFile, $stackPtr, $content, $characters),
            default => [$content, []],
        };

        $fix = false;
        foreach ($characters as $offset => $character) {
            $error = 'Invisible character %s found';
            $data = [self::describe($character)];
            if (in_array($offset, $fixable, true)) {
                $fix = $phpcsFile->addFixableError($error, $stackPtr, self::CODE_INVISIBLE_CHARACTER, $data) || $fix;
            } else {
                $phpcsFile->addError($error, $stackPtr, self::CODE_INVISIBLE_CHARACTER, $data);
            }
        }

        if (!$fix) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($stackPtr, $fixed);
        if ($code === T_NOWDOC) {
            self::openAsHeredoc($phpcsFile, $stackPtr);
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * Fix code or a comment: each character becomes a space, or is removed if it has no width. A tab becomes spaces
     * to the next tab stop, and a carriage return before a line feed is removed.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @param string $content The token's content.
     * @return string The fixed content.
     */
    private function fixText(File $phpcsFile, int $stackPtr, string $content): string
    {
        $tabWidth = $phpcsFile->config->tabWidth > 0 ? $phpcsFile->config->tabWidth : 4;
        $column = $phpcsFile->getTokens()[$stackPtr]['column'];
        $fixed = '';
        $chars = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $chars = is_array($chars) ? $chars : str_split($content);
        foreach ($chars as $index => $char) {
            $replacement = $char;
            if ($char === "\t" && !$this->allowTabs) {
                $replacement = str_repeat(' ', $tabWidth - (($column - 1) % $tabWidth));
            } elseif ($char === "\r") {
                $replacement = ($chars[($index + 1)] ?? '') === "\n" ? '' : "\n";
            } elseif ($char !== "\t" && self::findCharacters($char) !== []) {
                $replacement = preg_match('/\p{Cf}/u', $char) === 1 ? '' : ' ';
            }

            $fixed .= $replacement;
            $column = $replacement === "\n" || $char === "\n" ? 1 : $column + mb_strlen($replacement);
        }

        return $fixed;
    }

    /**
     * Fix a string or a heredoc line: each character becomes an escape. A single-quoted string becomes double-quoted,
     * if it can.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @param string $content The token's content.
     * @param array<int, string> $characters Each character found, by its byte offset.
     * @return array{string, list<int>} The fixed content, and the offsets of the characters fixed.
     */
    private static function fixString(File $phpcsFile, int $stackPtr, string $content, array $characters): array
    {
        $tokens = $phpcsFile->getTokens();
        $code = $tokens[$stackPtr]['code'];
        $prefix = strspn($content, 'bB');
        $first = $content[$prefix] ?? '';
        if ($code === T_CONSTANT_ENCAPSED_STRING && self::isSingleQuoted($phpcsFile, $stackPtr)) {
            $isWhole = $first === "'" && strlen($content) > ($prefix + 1) && str_ends_with($content, "'");
            $body = substr($content, ($prefix + 1), -1);
            if (!$isWhole || str_contains($body, '$') || str_contains($body, '\\')) {
                return [$content, []];
            }

            $body = str_replace('"', '\"', $body);
            $escaped = self::escape($body, array_keys(self::findCharacters($body)), false);

            return [substr($content, 0, $prefix) . "\"{$escaped}\"", array_keys($characters)];
        }

        $isInterpolated = $code !== T_CONSTANT_ENCAPSED_STRING;
        $fixable = [];
        foreach (array_keys($characters) as $offset) {
            if (!$isInterpolated || !self::isInInterpolation(substr($content, 0, $offset), $characters[$offset])) {
                $fixable[] = $offset;
            }
        }

        return [self::escape($content, $fixable, true), $fixable];
    }

    /**
     * Fix a nowdoc line, by making the nowdoc a heredoc, if it has no `$` or `\` that would then mean something.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the line.
     * @param string $content The line.
     * @param array<int, string> $characters Each character found, by its byte offset.
     * @return array{string, list<int>} The fixed content, and the offsets of the characters fixed.
     */
    private static function fixNowdoc(File $phpcsFile, int $stackPtr, string $content, array $characters): array
    {
        $tokens = $phpcsFile->getTokens();
        $start = $phpcsFile->findPrevious(T_START_NOWDOC, $stackPtr);
        $end = $phpcsFile->findNext(T_END_NOWDOC, $stackPtr);
        if ($start === false || $end === false) {
            return [$content, []];
        }

        for ($i = ($start + 1); $i < $end; $i++) {
            if (str_contains($tokens[$i]['content'], '$') || str_contains($tokens[$i]['content'], '\\')) {
                return [$content, []];
            }
        }

        return [self::escape($content, array_keys($characters), false), array_keys($characters)];
    }

    /**
     * Rewrite a nowdoc's opening as a heredoc's, so its lines can hold escapes.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $stackPtr The position of a line in the nowdoc.
     */
    private static function openAsHeredoc(File $phpcsFile, int $stackPtr): void
    {
        $start = $phpcsFile->findPrevious(T_START_NOWDOC, $stackPtr);
        if ($start !== false) {
            $content = $phpcsFile->getTokens()[$start]['content'];
            $phpcsFile->fixer->replaceToken($start, str_replace("'", '', $content));
        }
    }

    /**
     * Check whether a T_CONSTANT_ENCAPSED_STRING token is part of a single-quoted string, by its first line.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @return bool True if it is.
     */
    private static function isSingleQuoted(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();
        $first = $stackPtr;
        while ($first > 0 && $tokens[($first - 1)]['code'] === T_CONSTANT_ENCAPSED_STRING) {
            $first--;
        }

        $content = $tokens[$first]['content'];

        return ($content[strspn($content, 'bB')] ?? '') === "'";
    }

    /**
     * Check whether a character in an interpolated string is inside an interpolation, or straight after a variable's
     * name, which a non-ASCII character would be part of.
     *
     * @param string $before The string's text before the character.
     * @param string $character The character.
     * @return bool True if it is.
     */
    private static function isInInterpolation(string $before, string $character): bool
    {
        $unescaped = preg_replace('/\\\\./s', '', $before) ?? $before;
        if (preg_match('/(\{\$|\$\{)[^}]*$/', $unescaped) === 1) {
            return true;
        }

        return strlen($character) > 1 && preg_match('/\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $unescaped) === 1;
    }

    /**
     * Rewrite characters as escapes.
     *
     * @param string $content The text.
     * @param list<int> $offsets The byte offsets of the characters to rewrite.
     * @param bool $checkBackslashes Whether a backslash before a character may be written as one, so it needs
     * escaping too: in a double-quoted string or a heredoc, `\` then a tab is a backslash and a tab.
     * @return string The text with escapes.
     */
    private static function escape(string $content, array $offsets, bool $checkBackslashes): string
    {
        rsort($offsets);
        foreach ($offsets as $offset) {
            $character = (string) mb_substr(substr($content, $offset), 0, 1);
            $escape = self::escapeFor($character, $content[($offset + strlen($character))] ?? '');
            if ($checkBackslashes && (strspn(strrev(substr($content, 0, $offset)), '\\') % 2) === 1) {
                $escape = "\\{$escape}";
            }

            $content = substr($content, 0, $offset) . $escape . substr($content, ($offset + strlen($character)));
        }

        return $content;
    }

    /**
     * Get the escape for a character in a double-quoted string.
     *
     * @param string $character The character.
     * @param string $next The byte after it, as `\0` before an octal digit would read it.
     * @return string The escape.
     */
    private static function escapeFor(string $character, string $next): string
    {
        if (isset(self::LETTER_ESCAPES[$character])) {
            return self::LETTER_ESCAPES[$character];
        }

        if ($character === "\0") {
            return preg_match('/[0-7]/', $next) === 1 ? '\x00' : '\0';
        }

        if (strlen($character) === 1) {
            return sprintf('\x%02X', ord($character));
        }

        return sprintf('\u{%04X}', mb_ord($character));
    }

    /**
     * Describe a character, as its code point and name.
     *
     * @param string $character The character.
     * @return string The description, such as `U+00A0 (NO-BREAK SPACE)`.
     */
    private static function describe(string $character): string
    {
        $codePoint = mb_ord($character);
        if ($codePoint === false) {
            return sprintf('0x%02X', ord($character));
        }

        $name = self::CONTROL_NAMES[$codePoint] ?? '';
        if ($name === '' && class_exists(IntlChar::class)) {
            $name = IntlChar::charName($codePoint);
        }

        $name = $name === null || $name === '' ? '' : " ({$name})";

        return sprintf('U+%04X%s', $codePoint, $name);
    }
}
