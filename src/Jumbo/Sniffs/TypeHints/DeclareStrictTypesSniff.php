<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\TypeHints;

use PERCS31\Helpers\FileHeaderHelper;
use PERCS31\Sniffs\Files\FileHeaderSniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Requires `declare(strict_types=1)`.
 *
 * It accepts `strict_types=1` in any of the declare statements at the top of the file, as PHP does, in any case. The
 * other sniffs check the rest: PERCS31.Files.DeclareStatement reports a directive that isn't in lowercase, and spaces
 * inside the parentheses. PERCS31.Files.FileHeader checks the blank lines around the declare statements, and in a file
 * with markup, that the declaration is on the first line, as in `<?php declare(strict_types=1) ?>`, as PER Coding
 * Style 3.1, section 3, requires.
 *
 * This replaces SlevomatCodingStandard.TypeHints.DeclareStrictTypes, whose other checks those sniffs already make,
 * and whose fix adds strict_types to an existing declare statement.
 *
 * The fix for a missing declaration adds `declare(strict_types=1);` as a separate statement, before any other declare
 * statements, instead of adding a directive to one: in a block declare, that's a fatal error. In a file with markup,
 * including a template with only `<?=` tags, it adds the first-line form at the top instead. PHP skips a shebang line,
 * so in a file that starts with one, the form goes on the second line.
 */
class DeclareStrictTypesSniff implements Sniff
{
    /**
     * The error code for a missing declaration.
     */
    public const string CODE_MISSING = 'DeclareStrictTypesMissing';

    /**
     * The error code for strict_types set to 0.
     */
    public const string CODE_DISABLED = 'DeclareStrictTypesDisabled';

    /**
     * Returns the tokens this sniff wants to listen for.
     *
     * @return array<int|string> The tokens.
     */
    public function register(): array
    {
        return [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO];
    }

    /**
     * Processes this sniff, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the opening tag.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // Only the file's first opening tag.
        if ($phpcsFile->findPrevious([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], ($stackPtr - 1)) !== false) {
            return;
        }

        $directive = $tokens[$stackPtr]['code'] === T_OPEN_TAG
            ? FileHeaderHelper::findStrictTypesDirective($phpcsFile, $stackPtr)
            : null;
        if ($directive === null) {
            $this->reportMissing($phpcsFile, $stackPtr);
        } else {
            $this->checkValue($phpcsFile, $directive);
        }
    }

    /**
     * Report strict_types set to a value other than 1, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $directive The position of the strict_types directive.
     */
    private function checkValue(File $phpcsFile, int $directive): void
    {
        $tokens = $phpcsFile->getTokens();
        $equals = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($directive + 1), null, true);
        $value = $equals === false ? false : $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($equals + 1), null, true);
        if ($value === false || $tokens[$value]['code'] !== T_LNUMBER || $tokens[$value]['content'] === '1') {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            'Expected strict_types=1, found strict_types=%s',
            $value,
            self::CODE_DISABLED,
            [$tokens[$value]['content']],
        );
        if ($fix) {
            $phpcsFile->fixer->replaceToken($value, '1');
        }
    }

    /**
     * Report a missing declaration, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $tagPtr The position of the file's first opening tag.
     */
    private function reportMissing(File $phpcsFile, int $tagPtr): void
    {
        $tokens = $phpcsFile->getTokens();
        $eol = $phpcsFile->eolChar;

        if ($tokens[$tagPtr]['code'] === T_OPEN_TAG_WITH_ECHO || FileHeaderHelper::hasMarkup($phpcsFile)) {
            $hasShebang = str_starts_with($tokens[0]['content'], '#!');
            $fix = $phpcsFile->addFixableError(
                'Missing declare(strict_types=1), which in a file with markup goes on the first line%s, as in <?php '
                    . 'declare(strict_types=1) ?>',
                $tagPtr,
                self::CODE_MISSING,
                [$hasShebang ? ' after the shebang line' : ''],
            );
            if ($fix) {
                // PHP skips a shebang line, so the line after one is the first line it reads.
                $line = "<?php declare(strict_types=1) ?>{$eol}";
                if ($hasShebang) {
                    $phpcsFile->fixer->addContent(0, $line);
                } else {
                    $phpcsFile->fixer->addContentBefore(0, $line);
                }
            }

            return;
        }

        $fix = $phpcsFile->addFixableError('Missing declare(strict_types=1)', $tagPtr, self::CODE_MISSING);
        if (!$fix) {
            return;
        }

        // Before any other declare statements, as a separate statement. Otherwise, after the file docblock, as the
        // header's order is the opening tag, the file docblock, then the declare statements.
        $first = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($tagPtr + 1), null, true);
        if ($first !== false && $tokens[$first]['code'] === T_DECLARE) {
            $phpcsFile->fixer->addContentBefore($first, "declare(strict_types=1);{$eol}");
            return;
        }

        foreach (new FileHeaderSniff()->getHeaderLines($phpcsFile, $tagPtr) as $line) {
            if ($line['type'] === 'docblock' && is_int($line['end'])) {
                $phpcsFile->fixer->addContent($line['end'], "{$eol}{$eol}declare(strict_types=1);");
                return;
            }
        }

        // The opening tag's newline is in the white space after it. Code on the tag's line goes after a blank line.
        $next = $tagPtr + 1;
        $isWhitespace = $next < $phpcsFile->numTokens && $tokens[$next]['code'] === T_WHITESPACE;
        if ($isWhitespace && str_contains($tokens[$next]['content'], $eol)) {
            $phpcsFile->fixer->addContent($tagPtr, "{$eol}{$eol}declare(strict_types=1);");
            return;
        }

        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->addContent($tagPtr, "{$eol}{$eol}declare(strict_types=1);{$eol}{$eol}");
        if ($isWhitespace) {
            $phpcsFile->fixer->replaceToken($next, '');
        }

        $phpcsFile->fixer->endChangeset();
    }
}
