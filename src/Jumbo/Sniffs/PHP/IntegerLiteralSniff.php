<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PHP;

use Jumbo\Helpers\FunctionCallHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Checks how an integer literal is written: an explicit octal prefix, and the prefix in lower case with hex digits in
 * upper case.
 *
 * PER Coding Style 3.1 has no rule for number literals, so this is an extra rule. Two things are checked, each with
 * its own code, and fixed, with the same value:
 * - OctalPrefix: a legacy octal literal, `0755`, written `0o755`, which PHP 8.1 added. Only when PHP_CodeSniffer's
 *   `php_version` setting is 8.1 or later, as the project may support an older PHP.
 * - LiteralCase: the prefix in lower case and hex digits in upper case, as in `0xFF`, not `0XFF` or `0xff`, `0b101`,
 *   not `0B101`, and `0o17`, not `0O17`. A lower-case prefix stands out from both the leading `0` and the digits.
 */
class IntegerLiteralSniff implements Sniff
{
    public const string CODE_OCTAL_PREFIX = 'OctalPrefix';

    public const string CODE_LITERAL_CASE = 'LiteralCase';

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_LNUMBER, T_DNUMBER];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $content = $phpcsFile->getTokens()[$stackPtr]['content'];
        if (preg_match('/^0(?:_?[0-7])+$/', $content) === 1) {
            if ((FunctionCallHelper::minimumPhpVersion() ?? 0) >= 80100) {
                $expected = '0o' . substr($content, 1);
                $error = 'Write an octal literal with the 0o prefix; expected %s but found %s';
                if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_OCTAL_PREFIX, [$expected, $content])) {
                    $phpcsFile->fixer->replaceToken($stackPtr, $expected);
                }
            }

            return;
        }

        if (preg_match('/^0([xXbBoO])(.+)$/', $content, $match) !== 1) {
            return;
        }

        $prefix = strtolower($match[1]);
        $digits = $prefix === 'x' ? strtoupper($match[2]) : $match[2];
        $expected = "0{$prefix}{$digits}";
        if ($expected === $content) {
            return;
        }

        $error = 'Write a number\'s prefix in lower case and its hex digits in upper case; expected %s but found %s';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_LITERAL_CASE, [$expected, $content])) {
            $phpcsFile->fixer->replaceToken($stackPtr, $expected);
        }
    }
}
