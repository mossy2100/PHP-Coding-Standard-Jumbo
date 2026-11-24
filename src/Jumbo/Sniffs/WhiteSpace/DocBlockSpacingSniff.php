<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\WhiteSpace;

use PHP_CodeSniffer\Files\File;
use PhpCollective\Sniffs\WhiteSpace\DocBlockSpacingSniff as PhpCollectiveSniff;
use PHPCSUtils\Utils\Scopes;

/**
 * Disallows a blank line between a doc comment and the declaration it's for, including a property, a constant, an
 * enum and an enum case.
 *
 * PER Coding Style 3.1 has no rule for doc comments, so this is an extra rule. This extends
 * PhpCollective.WhiteSpace.DocBlockSpacing, which only checks a class, interface, trait or function. Its token list is
 * hard-coded in `register()`, which a ruleset can't change, so this only registers more tokens: a constant (`const`,
 * in a class or at file scope), an enum and an enum case, and a variable that's a property, so a local variable's
 * inline `@var` is left alone. The check itself is the parent's: from the start of the declaration's line, the doc
 * comment's end must be on the line before.
 *
 * With an attribute between the doc comment and the declaration, the attribute's line is the one checked. A blank line
 * between a doc comment and an attribute is left to the attribute sniffs.
 */
class DocBlockSpacingSniff extends PhpCollectiveSniff
{
    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [...parent::register(), T_CONST, T_ENUM, T_ENUM_CASE, T_VARIABLE];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    // phpcs:ignore Jumbo.TypeHints.ParameterTypeHint.MissingNativeTypeHint -- The parent's $stackPtr has no type.
    public function process(File $phpcsFile, $stackPtr): void
    {
        if ($phpcsFile->getTokens()[$stackPtr]['code'] === T_VARIABLE && !Scopes::isOOProperty($phpcsFile, $stackPtr)) {
            return;
        }

        parent::process($phpcsFile, $stackPtr);
    }
}
