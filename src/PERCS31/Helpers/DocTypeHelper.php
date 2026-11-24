<?php

declare(strict_types=1);

namespace PERCS31\Helpers;

use PHP_CodeSniffer\Files\File;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use SlevomatCodingStandard\Helpers\Annotation;
use SlevomatCodingStandard\Helpers\AnnotationHelper;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\PhpDocParserHelper;

/**
 * Helper class for the types in a doc comment: in `@param`, `@return`, `@var`, `@property`, `@method` and the rest,
 * including inside generics, read through Slevomat's AnnotationHelper, which caches each doc comment's parse.
 *
 * A fix rewrites the doc comment with the parser's format-preserving printer, so only the types changed are reprinted,
 * and every change to one doc comment is made at once.
 */
class DocTypeHelper
{
    /**
     * Find the type names in a doc comment's tags, such as `int` or `Foo` in `@param array<int, Foo> $a`. An array
     * shape's keys aren't types, so they aren't included, and neither is a tag that doesn't parse.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $docOpener The position of the doc comment's `/**`.
     * @return list<array{annotation: Annotation<PhpDocTagValueNode>, node: IdentifierTypeNode}> Each name, with its
     *     tag.
     */
    public static function findIdentifiers(File $phpcsFile, int $docOpener): array
    {
        $found = [];
        foreach (AnnotationHelper::getAnnotations($phpcsFile, $docOpener) as $annotation) {
            if ($annotation->isInvalid()) {
                continue;
            }

            $nodes = AnnotationHelper::getAnnotationNodesByType($annotation->getNode(), IdentifierTypeNode::class);
            foreach ($nodes as $node) {
                if ($node instanceof IdentifierTypeNode) {
                    $found[] = ['annotation' => $annotation, 'node' => $node];
                }
            }
        }

        return $found;
    }

    /**
     * Rewrite a doc comment with type names changed.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $docOpener The position of the doc comment's `/**`.
     * @param callable(string): string $rename Gives each type name's new name, or the same name to keep it.
     * @return ?string The doc comment's new code, or null if nothing changes.
     */
    public static function renameIdentifiers(File $phpcsFile, int $docOpener, callable $rename): ?string
    {
        $parsed = DocCommentHelper::parseDocComment($phpcsFile, $docOpener);
        if ($parsed === null) {
            return null;
        }

        $clone = PhpDocParserHelper::cloneNode($parsed->getNode());
        if (!$clone instanceof PhpDocNode) {
            return null;
        }

        $changed = false;
        foreach ($clone->getTags() as $tag) {
            foreach (AnnotationHelper::getAnnotationNodesByType($tag, IdentifierTypeNode::class) as $node) {
                $newName = $node instanceof IdentifierTypeNode ? $rename($node->name) : null;
                if ($node instanceof IdentifierTypeNode && $newName !== null && $newName !== $node->name) {
                    $node->name = $newName;
                    $changed = true;
                }
            }
        }

        if (!$changed) {
            return null;
        }

        return PhpDocParserHelper::getPrinter()->printFormatPreserving(
            $clone,
            $parsed->getNode(),
            $parsed->getTokens(),
        );
    }

    /**
     * Rewrite a doc comment with types replaced, from the innermost out, so a type's members are already replaced when
     * it's given to the callback. A type the callback gives a new node for is reprinted in full; the rest of the doc
     * comment is kept as written.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $docOpener The position of the doc comment's `/**`.
     * @param callable(TypeNode): ?TypeNode $transform Gives a type's replacement, or null to keep it. The type is a
     *     copy, whose Attribute::ORIGINAL_NODE is the type as parsed.
     * @return ?string The doc comment's new code, or null if nothing changes.
     */
    public static function transformTypes(File $phpcsFile, int $docOpener, callable $transform): ?string
    {
        $parsed = DocCommentHelper::parseDocComment($phpcsFile, $docOpener);
        if ($parsed === null) {
            return null;
        }

        $clone = PhpDocParserHelper::cloneNode($parsed->getNode());
        $visitor = new class ($transform) extends AbstractNodeVisitor {
            public bool $changed = false;

            /** @param callable(TypeNode): ?TypeNode $transform Gives a type's replacement, or null to keep it. */
            public function __construct(private $transform) {}

            /**
             * Replace a type, once its members are done.
             *
             * @param Node $node The node.
             * @return ?Node The replacement, or null to keep the node.
             */
            public function leaveNode(Node $node): ?Node
            {
                $replacement = $node instanceof TypeNode ? ($this->transform)($node) : null;
                $this->changed = $this->changed || $replacement !== null;

                return $replacement;
            }
        };
        $traverser = new NodeTraverser([$visitor]);
        [$transformed] = $traverser->traverse([$clone]);
        if (!$visitor->changed || !$transformed instanceof PhpDocNode) {
            return null;
        }

        return PhpDocParserHelper::getPrinter()->printFormatPreserving(
            $transformed,
            $parsed->getNode(),
            $parsed->getTokens(),
        );
    }

    /**
     * Replace a doc comment with new code.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $docOpener The position of the doc comment's `/**`.
     * @param string $content The doc comment's new code.
     */
    public static function replace(File $phpcsFile, int $docOpener, string $content): void
    {
        $closer = $phpcsFile->getTokens()[$docOpener]['comment_closer'];
        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($docOpener, $content);
        for ($i = ($docOpener + 1); $i <= $closer; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->endChangeset();
    }
}
