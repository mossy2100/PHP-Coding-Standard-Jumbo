<?php

namespace Vendor\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the calculator.
 *
 * @covers \Vendor\Calculator
 * @group slow
 */
class CalculatorTest extends TestCase
{
    public function setUp(): void {}

    public static function setUpBeforeClass(): void {}

    protected function tearDown(): void {}

    /**
     * @test
     */
    public function addsTwoNumbers(): void {}

    /**
     * Subtracts.
     *
     * @dataProvider provideCases
     * @depends addsTwoNumbers
     */
    public function testSubtracts(int $a): void {}

    /**
     * @test
     * @testWith [1, 2]
     *           [3, 4]
     */
    public function testWithRows(int $a, int $b): void {}

    /**
     * @covers ::foo
     */
    public function testWrongPlace(): void {}

    /**
     * Requirements.
     *
     * @requires PHP 8.4
     * @requires extension mbstring
     * @backupGlobals enabled
     * @return void Nothing.
     */
    #[\PHPUnit\Framework\Attributes\Group('fast')]
    public function testRequires(): void {}

    /**
     * @requires nonsense
     */
    public function testUnreadable(): void {}

    /**
     * @dataProvider provideCases
     */
    #[DataProvider('provideCases')]
    public function testAlreadyHasTheAttribute(int $a): void {}

    #[\PHPUnit\Framework\Attributes\Depends('addsTwoNumbers')]
    public function testDependsOnTheRenamedOne(): void {}

    /**
     * @return list<array{int}>
     */
    public static function provideCases(): array
    {
        return [[1]];
    }
}

/**
 * @group slow
 */
class NotATest extends Something
{
    /**
     * @test
     */
    public function setUp(): void {}
}
