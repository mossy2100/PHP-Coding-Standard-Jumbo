<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\NamingConventions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires a namespace's name to be in ASCII.
 *
 * PER Coding Style 3.1 has no rule for this, so this is an extra rule, widened from php-cs-fixer's
 * `no_homoglyph_names`. PHP accepts any byte from `\x80` in a name, so any Unicode character, including invisible ones
 * and look-alike letters, such as a Cyrillic `а` for a Latin `a`. Two names can then look the same and be different.
 * Jumbo's other naming sniffs already reject a non-ASCII class, function, method, property, constant or variable name;
 * this covers a namespace. Reported as NonAscii, and not fixed: there's no safe transliteration, and renaming one use
 * of a namespace breaks the others.
 */
class ValidNamespaceNameSniff implements Sniff
{
    public const string CODE_NON_ASCII = 'NonAscii';

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_NAMESPACE];
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
        $name = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($stackPtr + 1), null, true);
        if ($name === false || !in_array($tokens[$name]['code'], [T_STRING, T_NAME_QUALIFIED], true)) {
            return;
        }

        $content = $tokens[$name]['content'];
        if (preg_match('/[\x80-\xff]/', $content) === 1) {
            $phpcsFile->addError('Namespace name "%s" must be in ASCII', $name, self::CODE_NON_ASCII, [$content]);
        }
    }
}
