<?php

namespace App;

use datetime;
use DateTimeZone as zone;
use App\Models\Arrayobject;
use Random\randomizer, Random\Engine;
use Countable;

// Classes: allowed.
$a = new \DateTime();
$a = new zone();
$a = new Arrayobject();
$a = new Datetimeimmutable();
$a = new \App\Exception();
$a = PHP_EOL;

// Classes: reported.
$a = new datetime();
$a = new \stdclass();
$a = \DATETIME::createFromFormat('Y', '2026');
$a = $b instanceof \traversable;
$a = \closure::fromCallable('strlen');
$a = new randomizer();
$a = new \random\RANDOMIZER();

function one(\iterator $a, countable $b): \generator
{
    try {
    } catch (\exception $e) {
    }
}

#[\override]
function two()
{
}

// Magic methods: allowed.
class Three
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __tostringy()
    {
    }

    public function toString()
    {
        return $this->__toString();
    }
}

function __tostring()
{
}

// Magic methods: reported.
class Four
{
    public function __CONSTRUCT()
    {
        parent::__Construct();
    }

    public function __tostring(): string
    {
        return $this?->__TOSTRING() . self::__callstatic('a', []);
    }

    public function &__GET($name)
    {
    }

    public function __debuginfo(): array
    {
    }
}
