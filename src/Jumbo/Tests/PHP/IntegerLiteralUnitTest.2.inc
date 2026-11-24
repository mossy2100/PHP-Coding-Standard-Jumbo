<?php

// Valid.
$a = 0;
$b = 10;
$c = 0xFF;
$d = 0b101;
$e = 0o17;
$f = 1_000;
$g = 0.5;
$h = 0xDEAD_BEEF;

// Invalid: the case.
$i = 0XFF;
$j = 0xff;
$k = 0B101;
$l = 0O17;
$m = 0xdead_beef;

// Valid without php_version: a legacy octal, as the project may support PHP before 8.1.
$n = 0755;
$o = 07_55;
