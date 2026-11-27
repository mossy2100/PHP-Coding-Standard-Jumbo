<?php

declare(strict_types=1);

namespace PERCS31\Helpers;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Helper class for PERCS31.Formatting.ExpressionFormat's check of a string conversion that `echo`, `print` or `<?=`
 * already does, as in `echo (string) $x` or `echo $x->__toString()`.
 */
class StringConversionHelper
{
    /**
     * Check each argument of `echo`, `print` or `<?=` for a `(string)` cast or `->__toString()` call, which isn't
     * needed, as they convert their arguments to strings.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the keyword or tag.
     * @param string $code The error code to report it as.
     */
    public static function process(File $phpcsFile, int $stackPtr, string $code): void
    {
        $tokens = $phpcsFile->getTokens();
        $ptr = $stackPtr;
        do {
            $argument = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($ptr + 1), null, true);
            $region = $argument === false ? null : ExpressionHelper::findExpression($phpcsFile, $argument);
            if ($region === null) {
                return;
            }

            [$start, $end, , $after] = $region;
            $node = ExpressionParser::parse($phpcsFile, $start, $end);
            if ($node !== null && $node->kind !== ExpressionNode::LEVEL) {
                self::processConversion($phpcsFile, $stackPtr, $start, $end, $code);
            }

            $ptr = $after;
        } while ($tokens[$stackPtr]['code'] !== T_PRINT && ($tokens[$ptr]['code'] ?? null) === T_COMMA);
    }

    /**
     * Report a `(string)` cast or `->__toString()` call around a whole argument of `echo`, `print` or `<?=`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $keyword The position of the keyword or tag.
     * @param int $start The position of the argument's first token.
     * @param int $end The position of its last token.
     * @param string $code The error code to report it as.
     */
    private static function processConversion(File $phpcsFile, int $keyword, int $start, int $end, string $code): void
    {
        $tokens = $phpcsFile->getTokens();
        $from = null;
        $to = null;
        if ($tokens[$start]['code'] === T_STRING_CAST && $start < $end) {
            $from = $start;
            $to = (int) $phpcsFile->findNext(T_WHITESPACE, ($start + 1), null, true) - 1;
        } elseif (
            ($end - 4) >= $start && $tokens[$end]['code'] === T_CLOSE_PARENTHESIS
            && $tokens[($end - 1)]['code'] === T_OPEN_PARENTHESIS && $tokens[($end - 2)]['code'] === T_STRING
            && strtolower($tokens[($end - 2)]['content']) === '__tostring'
            && $tokens[($end - 3)]['code'] === T_OBJECT_OPERATOR
        ) {
            $from = $end - 3;
            $to = $end;
        }

        if ($from === null) {
            return;
        }

        $keywordName
            = $tokens[$keyword]['code'] === T_OPEN_TAG_WITH_ECHO ? '<?=' : strtolower($tokens[$keyword]['content']);
        $fix = $phpcsFile->addFixableWarning(
            'The string conversion isn\'t needed, as %s converts its arguments to strings.',
            $from,
            $code,
            [$keywordName],
        );
        if ($fix) {
            $phpcsFile->fixer->beginChangeset();
            for ($i = $from; $i <= $to; $i++) {
                $phpcsFile->fixer->replaceToken($i, '');
            }

            $phpcsFile->fixer->endChangeset();
        }
    }
}
