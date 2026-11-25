<?php

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
switch ($a) {
    case 1: {
        foo();
        break;
    }
}
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
    }
        break;
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
