<?php

// Valid: canonical, a class name, an array shape's key, and an unknown tag.
/**
 * @param int|null $a The value.
 * @param Integer\Thing $b A class in a namespace named Integer.
 * @param array{integer: int} $c A shape.
 * @custom INTEGER Not a type.
 * @return list<string>
 */
function valid($a, $b, $c): array {}

// Invalid: long names and types in other cases, in each kind of tag, and inside generics.
/**
 * @param integer $a The value.
 * @param array<string, BOOLEAN>|NULL $b The map.
 * @return Mixed
 */
function invalid($a, $b) {}

/** @var Double $x */
$x = 1.0;

/**
 * @property STRING $name
 * @method Int count(Bool $all)
 */
class Magic {}
