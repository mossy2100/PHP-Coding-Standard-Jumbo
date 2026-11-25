<?php

// Stringable: allowed.
class Seven implements Countable
{
    public function __toString(): string
    {
        return '';
    }
}

class Eight extends One
{
    public function __toString(): string
    {
        return '';
    }
}

// Stringable: reported.
class Nine
{
    public function __toString(): string
    {
        return '';
    }
}

// Serialization: warnings.
class Ten
{
    public function __sleep(): array
    {
        return [];
    }

    public function __wakeup(): void
    {
        echo 1;
    }
}
