<?php

class A
{
    /** @var list<string> */
    public array $valid = [];

    /** @var list<string> $names */
    public array $names = [];

    /** @var string */
    public int $wrong = 0;

    /**@var int*/
    public int $tight = 0;

    /** @var   int   The count. */
    public int $spaced = 0;

    /** @var int */
    public const int LIMIT = 1;

    /** @var */
    public $nothing;

    public function run(): void
    {
        /** @var int $a */
        $a = foo();

        /** @var $b int */
        $b = foo();

        /* @var int $c */
        $c = foo();
    }
}
