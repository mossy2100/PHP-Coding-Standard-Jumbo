<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Requires `//` for a single-line comment, not `#`, except for the words in `exceptions`, such as `#region`.
 *
 * PER Coding Style 3.1 has no rule for comments, so this is an extra rule. `# Text` is reported, and fixed to
 * `// Text`. Only the marker changes, never the comment's text; a space is added after `//` when the text starts
 * straight after the `#`, as Squiz.Commenting.InlineComment requires one.
 *
 * An exception is a whole word straight after the `#`, matched case-sensitively, then a space or the end of the line:
 * with the default, `#region Constants` and `#endregion` are allowed, as editors fold code on them, but `#regional`,
 * `#Region` and `# region` aren't. An attribute (`#[...]`) isn't a comment, and a shebang line comes before `<?php`,
 * so neither is checked.
 */
class DisallowHashCommentsSniff implements Sniff
{
    public const string CODE_HASH_COMMENT = 'HashComment';

    /**
     * The words allowed straight after `#`, as a comma-separated list. It's null if a `phpcs:set` comment clears it.
     */
    public ?string $exceptions = 'region,endregion';

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_COMMENT];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        // The original content, as the tab width setting replaces a tab in the comment's text with spaces.
        $token = $phpcsFile->getTokens()[$stackPtr];
        $content = $token['orig_content'] ?? $token['content'];
        if (!str_starts_with($content, '#')) {
            return;
        }

        $text = substr($content, 1);
        $firstWord = preg_split('/[ \t\r\n]/', $text, 2)[0] ?? '';
        if ($firstWord !== '' && in_array($firstWord, $this->exceptionList(), true)) {
            return;
        }

        $error = 'Use // for a comment, not #';
        if (!$phpcsFile->addFixableError($error, $stackPtr, self::CODE_HASH_COMMENT)) {
            return;
        }

        $separator = $text === '' || in_array($text[0], [' ', "\t", "\r", "\n"], true) ? '' : ' ';
        $phpcsFile->fixer->replaceToken($stackPtr, "//{$separator}{$text}");
    }

    /**
     * Get the exception words from the comma-separated property.
     *
     * @return list<string> The words, with empty items dropped.
     */
    private function exceptionList(): array
    {
        $words = array_map(trim(...), explode(',', $this->exceptions ?? ''));

        return array_values(array_filter($words, static fn(string $word): bool => $word !== ''));
    }
}
