<?php

declare(strict_types=1);

namespace Jumbo\Helpers;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * Helper class for comparing a doc comment's type with a native type, as Jumbo.Commenting.PhpDocTags does for
 * `@param`, `@return` and `@var`.
 *
 * A doc type matches if each of its members is the native type or a more specific form of it, going by PHPStan's types
 * (https://phpstan.org/writing-php-code/phpdoc-types): `list<int>` for `array`, `positive-int` for `int`, and so on. A
 * class, a template type or a type alias can't be compared without loading code, so a doc type with one is never said
 * not to match.
 */
class DocTypeMatchHelper
{
    /**
     * The kinds of PHP value each doc type name is, by lower-case name.
     *
     * @var array<string, list<string>>
     */
    private const array KINDS = [
        'int' => ['int'],
        'integer' => ['int'],
        'positive-int' => ['int'],
        'negative-int' => ['int'],
        'non-positive-int' => ['int'],
        'non-negative-int' => ['int'],
        'non-zero-int' => ['int'],
        'float' => ['float'],
        'double' => ['float'],
        'string' => ['string'],
        'non-empty-string' => ['string'],
        'numeric-string' => ['string'],
        'class-string' => ['string'],
        'interface-string' => ['string'],
        'trait-string' => ['string'],
        'enum-string' => ['string'],
        'literal-string' => ['string'],
        'lowercase-string' => ['string'],
        'uppercase-string' => ['string'],
        'non-empty-lowercase-string' => ['string'],
        'non-empty-uppercase-string' => ['string'],
        'non-empty-literal-string' => ['string'],
        'non-falsy-string' => ['string'],
        'truthy-string' => ['string'],
        'callable-string' => ['callable-string'],
        'bool' => ['bool'],
        'boolean' => ['bool'],
        'true' => ['true'],
        'false' => ['false'],
        'null' => ['null'],
        'array' => ['array'],
        'list' => ['array'],
        'non-empty-array' => ['array'],
        'non-empty-list' => ['array'],
        'callable-array' => ['array'],
        'iterable' => ['iterable'],
        'callable' => ['callable'],
        'pure-callable' => ['callable'],
        'object' => ['object'],
        'mixed' => ['mixed'],
        'void' => ['void'],
        'never' => ['never'],
        'never-return' => ['never'],
        'never-returns' => ['never'],
        'no-return' => ['never'],
        'scalar' => ['int', 'float', 'string', 'bool'],
        'numeric' => ['int', 'float', 'string'],
        'array-key' => ['int', 'string'],
        'self' => ['self'],
        'static' => ['self'],
        'resource' => ['resource'],
        'open-resource' => ['resource'],
        'closed-resource' => ['resource'],
    ];

    /**
     * PHP's own types in a native declaration.
     *
     * @var list<string>
     */
    private const array NATIVE_TYPES = [
        'array',
        'bool',
        'callable',
        'false',
        'float',
        'int',
        'iterable',
        'mixed',
        'never',
        'null',
        'object',
        'parent',
        'self',
        'static',
        'string',
        'true',
        'void',
    ];

    /**
     * Check whether a doc type matches a native type.
     *
     * @param TypeNode $docType The doc type.
     * @param string $nativeType The native type, as PHP_CodeSniffer gives it, such as `?int` or `int|string`.
     * @return ?bool True if it matches, false if it doesn't, or null if it can't be told.
     */
    public static function matches(TypeNode $docType, string $nativeType): ?bool
    {
        $kinds = self::docKinds($docType);
        if ($kinds === null) {
            return null;
        }

        $native = self::nativeTypes($nativeType);
        foreach ($kinds as $kind) {
            if (!self::kindMatches($kind, $native)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the kinds of PHP value a doc type can be.
     *
     * @param TypeNode $type The doc type.
     * @return list<string>|null The kinds, or null if a member can't be told without loading code.
     */
    private static function docKinds(TypeNode $type): ?array
    {
        if ($type instanceof UnionTypeNode || $type instanceof NullableTypeNode) {
            $members = $type instanceof UnionTypeNode ? $type->types : [$type->type, new IdentifierTypeNode('null')];
            $kinds = [];
            foreach ($members as $member) {
                $memberKinds = self::docKinds($member);
                if ($memberKinds === null) {
                    return null;
                }

                $kinds = [...$kinds, ...$memberKinds];
            }

            return $kinds;
        }

        return match (true) {
            $type instanceof IdentifierTypeNode => self::KINDS[strtolower($type->name)] ?? null,
            $type instanceof ThisTypeNode => ['self'],
            $type instanceof GenericTypeNode => self::genericKinds(strtolower((string) $type->type)),
            $type instanceof ArrayTypeNode, $type instanceof ArrayShapeNode => ['array'],
            $type instanceof ObjectShapeNode => ['object'],
            $type instanceof CallableTypeNode => in_array(
                strtolower((string) $type->identifier),
                ['callable', 'pure-callable'],
                true,
            )
                ? ['callable']
                : null,
            $type instanceof ConstTypeNode => match (true) {
                $type->constExpr instanceof ConstExprIntegerNode => ['int'],
                $type->constExpr instanceof ConstExprFloatNode => ['float'],
                $type->constExpr instanceof ConstExprStringNode => ['string'],
                default => null,
            },
            default => null,
        };
    }

    /**
     * Get the kinds of PHP value a generic doc type can be, by its base type.
     *
     * @param string $base The base type's lower-case name.
     * @return list<string>|null The kinds, or null for a generic class.
     */
    private static function genericKinds(string $base): ?array
    {
        return match ($base) {
            'array', 'list', 'non-empty-array', 'non-empty-list' => ['array'],
            'iterable' => ['iterable'],
            'int' => ['int'],
            'class-string', 'interface-string', 'trait-string', 'enum-string' => ['string'],
            default => null,
        };
    }

    /**
     * Get the members of a native type, in lower case, with any class as `class`.
     *
     * @param string $nativeType The native type.
     * @return list<string> The members.
     */
    private static function nativeTypes(string $nativeType): array
    {
        $types = [];
        if (str_starts_with($nativeType, '?')) {
            $types[] = 'null';
            $nativeType = substr($nativeType, 1);
        }

        foreach (explode('|', $nativeType) as $member) {
            $member = strtolower(trim($member));
            $types[] = in_array($member, self::NATIVE_TYPES, true) ? $member : 'class';
        }

        return $types;
    }

    /**
     * Check whether one kind of doc value fits a native type.
     *
     * @param string $kind The kind.
     * @param list<string> $native The native type's members.
     * @return bool True if it fits.
     */
    private static function kindMatches(string $kind, array $native): bool
    {
        $has = static fn(string $type): bool => in_array($type, $native, true);

        return $has('mixed') || $has($kind) || match ($kind) {
            'true', 'false' => $has('bool'),
            'bool' => $has('true') && $has('false'),
            'array' => $has('iterable'),
            'callable-string' => $has('string') || $has('callable'),
            'self' => $has('static') || $has('object') || $has('class') || $has('parent'),
            'object' => $has('class') || $has('self') || $has('static') || $has('parent'),
            'never' => $has('void'),
            default => false,
        };
    }
}
