<?php

$a = intval($x);
$b = intval($x, 16);
$c = strval($x + 1);
settype($d, 'integer');
settype($e, 'null');
$f = sizeof($list);
$g = \join(',', $list);
die('Stopped');
print 'Hello';
$h = print 'x';
ini_set('max_execution_time', '30');
$i = ini_set('max_execution_time', '30');
ini_set('memory_limit', '1G');
ini_alter('precision', '14');
ini_set('session.cookie_secure', '1');
if (strpos($haystack, $needle) !== false) {}
if (strpos($haystack, $needle) === 0) {}
if (false === strpos($haystack, 'a b')) {}
$j = 1 + strpos($haystack, $needle) !== false;
if (substr($h, 0, strlen($n)) === $n) {}
if (substr($h, -strlen('.php')) === '.php') {}
if (substr($h, -strlen($n)) === $n) {}
if (strncmp($h, $n, strlen($n)) !== 0) {}
if (strlen($s) === 0) {}
if (0 < strlen($s)) {}
array_push($list, $item);
array_push($list, $item, $other);
$k = implode($pieces);
$l = sprintf('text');
$m = sprintf('100%%');
printf('text');
$n = pow($p, 2);
$o = pow(-2, 2);
$q = pow($p, 2) ** 2;
call_user_func('foo', $a);
call_user_func([$obj, 'bar'], $a, $b);
call_user_func($callback, $a);
call_user_func_array($callback, $args);
call_user_func($foo, $foo = 'bar');
$r = fopen('file', 'rt');
$s2 = $obj->sizeof($list);
$t = $list[array_key_first($list)];
$u = $this->items[array_key_last($this->items)];
$v = $first[array_key_first($second)];
