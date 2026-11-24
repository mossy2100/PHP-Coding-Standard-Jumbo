<?php

namespace Vendor\Tests;

use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function testValid(): void
    {
        $this->assertIsInt($a);
        $this->assertTrue(is_int($a) && $b);
        $this->assertTrue(!is_int($a));
        $this->assertFalse(is_nan($a));
        $this->assertTrue(is_file($a));
        $this->assertTrue($object->is_int($a));
        $other->assertTrue(is_int($a));
    }

    public function testInvalid(): void
    {
        $this->assertSame(null, $a);
        $this->assertTrue(is_int($a));
        $this->assertFalse(is_string($a), 'Not a string.');
        self::assertTrue(is_null($a));
        static::assertFalse(file_exists($path));
        $this->assertTrue(array_key_exists('key', $array));
        $this->assertTrue(\is_dir($path));
        $this->assertTrue(is_nan($a));
    }
}

class NotATest
{
    public function run(): void
    {
        $this->assertTrue(is_int($a));
    }
}
