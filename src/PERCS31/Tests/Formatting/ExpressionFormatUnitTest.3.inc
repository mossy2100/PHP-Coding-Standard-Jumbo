<?php

// phpcs:set PERCS31.Formatting.ExpressionFormat reflowStringExpressions true
// phpcs:set PERCS31.Formatting.ExpressionFormat strict true
// Valid: a `key => value` pair that fits, or laid out by the rules, and a string that isn't split for what follows it.
$x = [
    'short' => 'This value is fine and fits.',
    'a_string_value_key'
        => 'This is a long value string that goes past the line length limit of one hundred and twenty.',
    'canCheckout' => (($age >= 18 && $age < 65) || $hasGuardian) && !$isBanned && !in_array('home', $places, true)
        && ($balance - $price * (1 - $discount / 100) >= 0 || $creditLimit > 0),
    'longDescription' => 'He would like to heal his mind and body, make amends within, and go on to become a '
        . 'world-famous best-selling comedy thriller science fiction author.',
];
$list = [
    'a value long enough that the array holding it, with its brackets, does not fit on one line of its own at all',
];
$message = 'A string that fits on its line, though with the comment after it the line is too long.'; // A comment.
$total = $firstNumber + $secondNumber + $thirdNumber + $fourthNumber; // A comment that takes the line past the limit.
$label = match ($code) {
    'first', 'second' => 'An arm with several conditions that fits on its line is left as it is, conditions and all.',
    default => 'none',
};
foreach ($someLongArrayVariableName as $someLongKeyVariableName => $someLongValueVariableName) { // A comment here.
}

// Invalid: a value that fits on a line of its own goes there, starting with the `=>`.
$x = [
    'a_string_value_key' => 'This is a long value string that goes past the line length limit of one hundred and twenty.',
    'joined_key' => 'This is a long value string that goes past the line length limit of one hundred and '
        . 'twenty.',
];
$label = match (true) {
    $firstNumber > 10 && $secondNumber > 20 && $thirdNumber > 30 && $fourthNumber > 40 => 'all of them are big enough here',
    default => 'none',
};

// Invalid: a key too long for its line is split, by the same rules as any string.
$x = [
    'a key that is so long that it goes on and on past the line length limit of one hundred and twenty characters, and more' => 1,
];

// Invalid: a pair that fits on one line, split before or after its `=>`.
$x = [
    'short'
        => 'value',
    'other' =>
        'value',
];

// phpcs:set PERCS31.Formatting.ExpressionFormat reflowStringExpressions false
// phpcs:set PERCS31.Formatting.ExpressionFormat strict false
