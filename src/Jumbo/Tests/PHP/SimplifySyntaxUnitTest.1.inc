<?php

// For loops: allowed.
for ($i = 0; $i < 10; $i++) {
}
for (; $i < 10; $i++) {
}
for (; $a, $b;) {
}

// For loops: reported.
for (; $i < 10;) {
}
for ( ; $i < 10 ; ) {
}
for (;;) {
}
for (; $i < 10;):
endfor;
for (/* start */; $i < 10;) {
}
for (; /* ever */;) {
}

// isset(): allowed.
$a = isset($a) || isset($b);
$a = !isset($a) && isset($b);
$a = isset($a) && isset($b) === $c;
$a = $c == isset($a) && isset($b);

// isset(): reported.
$a = isset($a) && isset($b);
$a = isset($a) && isset($b) && isset($c, $d);
if (isset($a['x']) && isset($b) || $c) {
}
$a = !isset($a) && isset($b) && isset($c);
$a = isset($a) && isset($b) && isset($c) === $d;
$a = isset($a,) && isset($b);
$a = isset($a) /* why */ && isset($b);

// unset(): allowed.
unset($a);
foo();
unset($b);
foo();
unset($a); // Done.
unset($b);
if ($a) unset($a);

// unset(): reported.
unset($a);
unset($b);
foo();
unset($a); unset($b); unset($c);
if ($a) unset($a); unset($b); unset($c);

// Nullsafe $this: allowed, then reported.
$a = $that?->b;
$a = $this->b?->c;
$a = $this?->b;
$a = $this?->b();

// Binary strings: allowed, then reported.
$a = 'b';
$a = B'abc';
$a = b"abc";
$a = b<<<EOT
    abc
    EOT;
$a = b<<<'EOT'
    abc
    EOT;

// Braces: allowed.
if ($a) {
}
$a = function () {
};
$a->{'b'} = 1;
{
    function bar()
    {
    }
}

// Braces: reported.
{
    foo();
}
switch ($a) {
    case 1: {
        foo();
        break;
    }
}
{ foo(); }

// Return: allowed.
function one()
{
    if ($a) {
        return;
    }

    foo();
}
function two()
{
    return 1;
}

// Return: reported.
function three()
{
    foo();

    return;
}
$a = function () {
    foo();
    return; // Done.
};
function four()
{
    return /* nothing */;
}
