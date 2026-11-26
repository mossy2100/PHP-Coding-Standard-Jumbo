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
// phpcs:set PERCS31.Formatting.ExpressionFormat strict false
// phpcs:set PERCS31.Formatting.ExpressionFormat maxLineLength 120
