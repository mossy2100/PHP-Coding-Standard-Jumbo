<?php
// phpcs:set PERCS31.Formatting.ExpressionFormat maxLineLength 40

// Valid: fits, or already split.
$short = foo($a, $b);
$result = calculate(
    $firstValue,
    $secondValue,
);

// Too long: chopped down, one argument per line.
$result = calculate($firstValue, $secondValue, $thirdValue);
foo($alpha, $beta, $gamma, $delta, $eps);

// Too long: chopped down, so the array fits on its own line.
$a = merge($defaults, ['colour' => 'red']);

// Too long: the array is too long even on its own line, so it's hugged, through two calls.
$a = merge($defaults, ['colour' => 'red', 'size' => 'large']);
$a = foo(bar(['colour' => 'red', 'size' => 'large']));

// Outermost first: the operator, unless the call after it is too long for its own line too, so it stays after it.
$ok = $admin || in_array($r, ['a', 'b'], true);
$ok = $admin || in_array($role, ['admin', 'editor', 'author'], true);

// An array's values, one per line, and an argument on its own line in a split call.
$list = ['alpha', 'beta', 'gamma', 'delta'];
$a = foo(
    bar($firstValue, $secondValue, $third),
);

// In strict, a call split over lines that fits on one is joined, and one hugging the wrong argument is re-laid out.
// phpcs:set PERCS31.Formatting.ExpressionFormat strict true
$short = foo(
    $a,
    $b,
);
$a = merge($defaults, [
    'colour' => 'red',
]);
$a = merge(
    $defaults,
    [
        'colour' => 'red',
        'size' => 'large',
    ],
);
$a = $b
    + foo(
        $c,
    );
// Closures are hugged, last or not, unless there are two; a match is chopped down, never hugged. (Moved bodies are
// re-indented by PERCS31.WhiteSpace.ScopeIndent.)
usort($items, function ($a, $b) {
    return $a <=> $b;
});
$b = array_map(function ($x) {
    return $x * 2;
}, $items);
$c = foo(
    $x,
    function () {
        return 1;
    },
);
$c = foo(function () {
    return 1;
}, function () {
    return 2;
});
$d = foo(match ($b) {
    1 => 'one',
    default => 'many',
});
// Pairs: a value that fits on its own line goes there, even with operators or as an array; a split key puts `=>` on a
// line of its own; and with no room after `key =>`, `=>` starts a line anyway.
$a = match ($b) {
    'some longer key' => $first + $second,
    $first > 10 && $second > 20 && $third > 30 => 'big',
    'abcdefghijklmnopqrstuvwxyz0123' => $first + $second + $third + $fourth,
};
$x = [
    'customer' => ['Shaun', 'Moss', 'XV', 55],
];
// A match arm with several conditions: a value on its own line, the conditions one per line with `=>` on its own, and
// conditions split that fit, joined without their trailing comma.
$a = match ($b) {
    42, 3.1416, 1000000 => 'this is slightly tricksy',
    0b01010101, 0b10101010, 0b00001111, 0b11110000 => 'blammo',
    1,
    2, => 'fits',
};
// Ternaries: three lines, or two for `?:`, even with a call in the condition; one in a group, after which the next
// operator starts a line; and one that fits, joined.
$label = $count === 1 ? 'one item' : "{$count} items";
$needle = strlen($a) === strlen($b) ? $other : null;
$v = $someLongVariableName ?: $anotherLongName;
$c = $here + $is + ($aLonger ? $result : $determined) * $from - $anExpression;
$fits = $a
    ? $b
    : $c;
// Arrow functions and `yield` pairs, laid out as pairs, the signature as the key; an arrow function as an ordinary
// argument; empty brackets and a destructuring list joined; one with a skipped element left alone.
function generate()
{
    yield $someLongKey => $someLongValue;
}
$f = fn(int $x): int => $x * $factor + $offset;
$h = static fn(int $value): int => $value * $someLongMultiplier + $offset + $more;
$r = array_map(fn($a) => $a + 1, $someList);
$empty = [
];
[
    $first,
    $second,
] = $pair;
[
    $a,
    ,
    $c,
] = $list;
// Nested arrow functions: the fewest breaks, the outer arrow first; every arrow broken when the body must be split.
$n = fn($first) => fn($b) => $first + $b + $cc;
$n = fn($first) => fn($second) => $first + $second + $third;
$n = fn($first) => fn($second) => $first + $second + $third + $fourth + $fifth;
$n = fn($a) => fn($b) => fn($c) => $a + $b + $c + $dd;
// phpcs:set PERCS31.Formatting.ExpressionFormat strict false
// phpcs:set PERCS31.Formatting.ExpressionFormat maxLineLength 120
