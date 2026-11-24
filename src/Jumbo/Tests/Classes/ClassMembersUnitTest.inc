<?php

// Protected: allowed in a class that isn't final, extends a class or uses a trait.
class One
{
    protected $a;
}

final class Two extends One
{
    protected $b;
}

final class Three
{
    use SomeTrait;

    protected $c;
}

// Protected: reported.
final class Four
{
    protected const A = 1;
    protected int $b = 2;
    public protected(set) int $c = 3;

    public function __construct(protected int $d)
    {
        $e = new class {
            protected $f;
        };
    }

    protected function g(): void
    {
    }
}

// Static methods: allowed.
class Five extends One
{
    private function a(): int
    {
        return $this->b;
    }

    private function b(): int
    {
        return parent::b();
    }

    private function c(): int
    {
        return $this->a() + self::d();
    }

    private function d(): string
    {
        return "{$this->b}";
    }

    private function e(): callable
    {
        return fn() => 1;
    }

    private function f(): string
    {
        return get_class();
    }

    private function g(): int
    {
        return self::inherited();
    }

    private static function h(): int
    {
        return 1;
    }

    public function i(): int
    {
        return 1;
    }

    private function __clone()
    {
    }
}

// Static methods: reported.
class Six
{
    private function a(int $x): int
    {
        return $x * self::b() + static::b();
    }

    private static function b(): callable
    {
        return static fn() => 1;
    }

    public function c(): int
    {
        return $this->a(1) + $this->a(2);
    }

    private function d(object $x): string
    {
        return get_class($x) . strlen('$this');
    }
}

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
