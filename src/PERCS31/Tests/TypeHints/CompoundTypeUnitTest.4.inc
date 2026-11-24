<?php

// Valid doc types: null last, ?T, no spaces, and a type that doesn't parse.
/**
 * @param ?string $a The value.
 * @param string|int|null $b Not sorted, which is allowed by default.
 * @param list<int|string|null> $c A union inside a generic.
 * @param int|int $d A repeat, allowed by default.
 * @return array{a: int|string}
 */
function valid($a, $b, $c, $d) {}

// Invalid doc types: null not last, ?T, and spaces, in each kind of tag and inside a generic.
/**
 * @param null|string $a The value.
 * @param int | string $b Spaced.
 * @param null|int|string $c Null first.
 * @return array<string, null|Foo>|false
 */
function invalid($a, $b, $c) {}

/** @var null|Foo $x */
$x = null;
