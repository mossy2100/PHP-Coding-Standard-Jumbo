<?php

// phpcs:set PERCS31.TypeHints.CompoundType sortTypes true
// phpcs:set PERCS31.TypeHints.CompoundType checkRedundantTypes true

// Sorted, in native and doc types, multi-catch and DNF included.
function sorted(string|int $a, (B&A)|C $b): void
{
    try {
        run();
    } catch (B|A $e) {
    }
}

/**
 * @param string|int|null $a Null stays last.
 * @param int|int|string $b Repeated.
 * @param true|bool $c Redundant, not fixed.
 * @param list<float>|array $d Redundant, not fixed.
 */
function docs($a, $b, $c, $d) {}

// phpcs:set PERCS31.TypeHints.CompoundType sortTypes false
// phpcs:set PERCS31.TypeHints.CompoundType checkRedundantTypes false
// phpcs:set PERCS31.TypeHints.CompoundType nullPosition first
// phpcs:set PERCS31.TypeHints.CompoundType checkShortNullable false

// null first, and no ?T.
function first(int|null $a, null|string $b): void {}

/** @param string|null $a Null last. */
function firstDoc($a) {}

// phpcs:set PERCS31.TypeHints.CompoundType nullPosition
// null anywhere.
function anywhere(int|null|string $a): void {}

// phpcs:set PERCS31.TypeHints.CompoundType nullPosition last
// phpcs:set PERCS31.TypeHints.CompoundType checkShortNullable true
