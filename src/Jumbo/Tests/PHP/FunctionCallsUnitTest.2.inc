<?php

namespace Vendor;

use function Other\sizeof;
use function intval;

function strlen(string $a): int
{
    return 0;
}

// Imported from elsewhere, and declared here: not PHP's.
$a = sizeof($x);
$b = strlen($s) === 0;

// A global function imported as itself, and a fully qualified call: PHP's.
$c = intval($x);
$d = \strlen($s) === 0;

// A string callable becomes a fully qualified call in a namespace.
call_user_func('foo', $a);

// Without php_version set, the project may run on PHP before 8.5, so array_first() isn't required.
$e = $list[array_key_first($list)];
