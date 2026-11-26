<?php

declare(strict_types=1);

namespace PERCS31\Helpers;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * Helper class for the members of a compound type: the order they're sorted in, and which one covers another.
 *
 * Which type covers which follows PHPStan's types (https://phpstan.org/writing-php-code/phpdoc-types). A class covers
 * nothing but itself, as subclassing can't be known without loading code.
 */
class TypeSetHelper
{
    /**
     * The types each type covers directly, by lower-case name. A type covers what those cover too.
     *
     * @var array<string, list<string>>
     */
    private const array COVERS = [
        'mixed' => ['*'],
        'scalar' => ['int', 'float', 'string', 'bool'],
        'numeric' => ['int', 'float', 'numeric-string'],
        'array-key' => ['int', 'string'],
        'int' => ['positive-int', 'negative-int', 'non-positive-int', 'non-negative-int', 'non-zero-int', '#int'],
        'float' => ['#float'],
        'string' => [
            'non-empty-string',
            'numeric-string',
            'class-string',
            'interface-string',
            'trait-string',
            'enum-string',
            'callable-string',
            'literal-string',
            'lowercase-string',
            'uppercase-string',
            'non-falsy-string',
            'truthy-string',
            '#string',
        ],
        'non-empty-string' => [
            'non-falsy-string',
            'truthy-string',
            'non-empty-lowercase-string',
            'non-empty-uppercase-string',
            'non-empty-literal-string',
        ],
        'bool' => ['true', 'false'],
        'iterable' => ['array'],
        'array' => ['list', 'non-empty-array', 'non-empty-list', '#array'],
        'list' => ['non-empty-list'],
        'callable' => ['callable-string', 'callable-array', 'callable-object', '#callable'],
    ];

    /**
     * Get the key a type is sorted by: its code in lower case, without a leading `\`.
     *
     * @param string $type The type's code.
     * @return string The key.
     */
    public static function sortKey(string $type): string
    {
        return ltrim(strtolower($type), '\\');
    }

    /**
     * Sort types by their keys, keeping the order of types with the same key.
     *
     * @template T
     * @param list<T> $types The types.
     * @param callable(T): string $code Gives each type's code.
     * @return list<T> The types, sorted.
     */
    public static function sort(array $types, callable $code): array
    {
        $keyed = [];
        foreach ($types as $index => $type) {
            $keyed[] = [self::sortKey($code($type)), $index, $type];
        }

        usort($keyed, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn(array $entry): mixed => $entry[2], $keyed);
    }

    /**
     * Find a member of a doc type's union that covers another, so the other is redundant.
     *
     * @param TypeNode $type The member that may be redundant.
     * @param list<TypeNode> $others The union's other members.
     * @return ?TypeNode The member that covers it, or null if none does.
     */
    public static function findCovering(TypeNode $type, array $others): ?TypeNode
    {
        $kind = self::kind($type);
        if ($kind === null) {
            return null;
        }

        foreach ($others as $other) {
            if (
                $other !== $type && $other instanceof IdentifierTypeNode
                && self::covers(strtolower($other->name), $kind)
            ) {
                return $other;
            }
        }

        return null;
    }

    /**
     * Get the kind of type a member is, for the coverage table: its name, or `#` and the base type for a literal, a
     * generic or another typed form.
     *
     * @param TypeNode $type The member.
     * @return ?string The kind, or null for a type that only a repeat of itself covers.
     */
    private static function kind(TypeNode $type): ?string
    {
        return match (true) {
            $type instanceof IdentifierTypeNode => strtolower($type->name),
            $type instanceof GenericTypeNode => self::genericKind(strtolower((string) $type->type)),
            $type instanceof ArrayShapeNode, $type instanceof ArrayTypeNode => '#array',
            $type instanceof CallableTypeNode => '#callable',
            $type instanceof ConstTypeNode => match (true) {
                $type->constExpr instanceof ConstExprIntegerNode => '#int',
                $type->constExpr instanceof ConstExprFloatNode => '#float',
                $type->constExpr instanceof ConstExprStringNode => '#string',
                default => null,
            },
            default => null,
        };
    }

    /**
     * Get the kind of a generic type, such as `list<int>` or `int<0, max>`, by its base type.
     *
     * @param string $base The base type's lower-case name.
     * @return ?string The kind, or null for a generic class.
     */
    private static function genericKind(string $base): ?string
    {
        return match ($base) {
            'array', 'list', 'non-empty-array', 'non-empty-list' => $base === 'array' ? '#array' : $base,
            'iterable' => '#iterable',
            'int' => '#int',
            'class-string', 'interface-string', 'trait-string', 'enum-string' => $base,
            default => null,
        };
    }

    /**
     * Check whether a type covers a kind of type, directly or through a type it covers.
     *
     * @param string $type The covering type's lower-case name.
     * @param string $kind The kind of the type covered.
     * @return bool True if it does.
     */
    private static function covers(string $type, string $kind): bool
    {
        if ($type === 'iterable' && $kind === '#iterable') {
            return true;
        }

        foreach (self::COVERS[$type] ?? [] as $covered) {
            if ($covered === '*' || $covered === $kind || ($covered !== $type && self::covers($covered, $kind))) {
                return true;
            }
        }

        return false;
    }
}
