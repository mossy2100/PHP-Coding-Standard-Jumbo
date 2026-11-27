<?php

// Valid: not only a return.
$a = function ($x) {
    $y = $x * 2;

    return $y;
};

// Valid: a bare return, which has nothing to put after =>.
$b = function (): void {
    return;
};

// Valid: an operator after the closure would join the arrow function's body.
$c = function () {
    return 1;
} ?? null;
$d = function () {
    return 'a';
} instanceof Closure;

// Valid: an outer variable, which the closure doesn't see but an arrow function would capture. The closure nested in
// the second is invalid, as its use list reads from the closure around it, and so would an arrow function.
$e = function () {
    return $outer;
};
$f = function () {
    return function () use ($outer) {
        return $outer;
    };
};

// Valid: a variable variable, or a nested arrow function, which aren't checked.
$g = function ($name) {
    return $$name;
};
$h = function () {
    return fn($y) => $y;
};

// Valid: a variable captured by reference, which the parent leaves alone.
$i = function () use (&$total) {
    return $total;
};

// Invalid: only a return, using only parameters, `use` variables, $this and superglobals.
$j = function ($x) {
    return $x * 2;
};
$k = function () use ($rate) {
    return $rate + 1;
};
$l = static function (int $x): int {
    return $x + count($_GET);
};
$m = function () {
    return $this->total;
};

// Invalid: the closure ends the expression, in a ternary, an argument or an array.
$n = $flag ? function () {
    return 1;
} : null;
usort($list, function ($p, $q) {
    return $p <=> $q;
});
$o = [function () {
    return 2;
}];

// Invalid: a nested closure, which only reads its use list from this scope.
$p = function ($x) {
    return function ($y) use ($x) {
        return $x + $y;
    };
};

// Invalid, but not fixed: a comment the fix would remove.
$q = function () {
    // Why.
    return 3;
};
