<?php

// Valid: on one line, too long to collapse, a chain of two or more, a comment, or a body.
$a = $b ? 1 : 2;
$abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz
    = $abcdefghijklmnopqrstuvwxyz;
$a
    ->b()
    ->c();
$d = $a
    ?->b()
    ?->c();
$a = $b // Why.
    + $c;
$a = $b ?? function () {
    return 1;
};
$a = <<<'X'
    b
    X
    . 'c';
// Left to their own sniffs, a split compound type too.
class G
{
    public A
        |B $j;
}
$a = foo(
    1,
);
$a = [
    1,
];
for (
    $i = 0;
    $i < 1;
    $i++
) {
    foo();
}
// Attributes and a case label aren't part of the statement.
class A
{
    #[B]
    public int $c = 1;
}
switch ($a) {
    case 1:
        $b = 2;
        break;
    default:
        $c = 3;
        break;
}
#[D]
const E = 1;
// Left to PERCS31.Formatting.ExpressionFormat: a line break at an assignment's operator, a property's default too,
// before a call's opening bracket, or in an `echo` list.
echo $a,
    $b;
$a = new
    Foo();
$a
    = $b ? 1 : 2;
$a =
    $b;
class J
{
    public int $h
        = 1;
}
$a =
    foo(function () {
        return 1;
    });
$a =
    [
        'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz',
        'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz',
        'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz',
    ];
// Left to PERCS31.Formatting.ExpressionFormat: a line break at a binary operator.
$a = $b
    + $c;
$a = $b
    ?? $c;
$a = $b
    && $c;
$a = $b ? $c
    . $d : 2;
function f()
{
    return $a
        + $b;
}
class H
{
    public const I = 1
        + 2;
}

// Invalid: all of these fit.
$a = (
    $b
);
$a = $b ? (
    $c
) : 2;
$a = $b
    ? 1
    : 2;
$a = $b
    ->c;
switch ($a) {
    case (
        $b
    ):
        break;
}
$a = foo(
    1,
)
    ->bar();

// Invalid: the lines around a body, or a call or array too long to collapse, are joined.
$a = $b
    ?? function () {
        return 1;
    };
$a = $b
    ? function () {
        return 1;
    }
    : null;
$a = $b
    ?? new class {
        public int $c;
    };
$a = $b
    ?? match ($c) {
        1 => 2,
        default => 3,
    };
$a = function () {
    return 1;
}
    ?? null;
$a = $b
    ?? ['abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz', 'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz'];

// Valid: a comment in the part to join, or a chain of two or more with a body.
$a = $b // Why.
    ?? function () {
        return 1;
    };
$a
    ->b(function () {
        return 1;
    })
    ->c();
