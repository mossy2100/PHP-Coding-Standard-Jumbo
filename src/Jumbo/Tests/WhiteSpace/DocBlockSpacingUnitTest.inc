<?php

/** A global constant. */
const VALID = 1;

/** A class, its members and an enum, each straight after its doc comment. */
class Valid
{
    /** @var int */
    public int $count = 0;

    /** A constant. */
    public const int LIMIT = 1;

    /** A method. */
    public function run(): void
    {
        /** @var int $local */

        $local = foo();
    }
}

/** An enum. */
enum Suit
{
    /** A case. */
    case Hearts;
}

/** A global constant. */

const INVALID = 1;

/** A class. */

class Invalid
{
    /** @var int */

    public int $count = 0;

    /** A constant. */

    public const int LIMIT = 1;

    /** A promoted property. */

    public function __construct(public int $a) {}
}

/** An enum. */

enum Colour
{
    /** A case. */

    case Red;
}
