<?php

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
