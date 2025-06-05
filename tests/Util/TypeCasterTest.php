<?php

declare(strict_types=1);

namespace Tests\YourOrm\Util;

use YourOrm\Util\TypeCaster;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class TypeCasterTest extends TestCase
{
    public function testCastToPhpTypeInt()
    {
        $this->assertSame(123, TypeCaster::castToPhpType('123', 'int'));
        $this->assertSame(0, TypeCaster::castToPhpType('abc', 'int'));
        $this->assertSame(1, TypeCaster::castToPhpType(true, 'int'));
    }

    public function testCastToPhpTypeString()
    {
        $this->assertSame('123', TypeCaster::castToPhpType(123, 'string'));
        $this->assertSame('hello', TypeCaster::castToPhpType('hello', 'string'));
        $this->assertSame('1', TypeCaster::castToPhpType(true, 'string'));
    }

    public function testCastToPhpTypeBool()
    {
        $this->assertTrue(TypeCaster::castToPhpType('1', 'bool'));
        $this->assertTrue(TypeCaster::castToPhpType('true', 'bool'));
        $this->assertTrue(TypeCaster::castToPhpType(1, 'bool'));
        $this->assertFalse(TypeCaster::castToPhpType('0', 'bool'));
        $this->assertFalse(TypeCaster::castToPhpType('false', 'bool'));
        $this->assertFalse(TypeCaster::castToPhpType(0, 'bool'));
        $this->assertNull(TypeCaster::castToPhpType('abc', 'bool')); // FILTER_NULL_ON_FAILURE
        $this->assertFalse(TypeCaster::castToPhpType('', 'bool'));
    }

    public function testCastToPhpTypeFloat()
    {
        $this->assertSame(123.45, TypeCaster::castToPhpType('123.45', 'float'));
        $this->assertSame(0.0, TypeCaster::castToPhpType('abc', 'float'));
    }

    public function testCastToPhpTypeDateTimeImmutable()
    {
        $dateString = '2023-01-01 10:00:00';
        $dt = TypeCaster::castToPhpType($dateString, 'DateTimeImmutable');
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertEquals($dateString, $dt->format('Y-m-d H:i:s'));

        $existingDt = new DateTimeImmutable($dateString);
        $this->assertSame($existingDt, TypeCaster::castToPhpType($existingDt, 'DateTimeImmutable'));

        $timestamp = strtotime($dateString);
        $dtFromTimestamp = TypeCaster::castToPhpType($timestamp, 'DateTimeImmutable');
        $this->assertInstanceOf(DateTimeImmutable::class, $dtFromTimestamp);
        $this->assertEquals($timestamp, $dtFromTimestamp->getTimestamp());

        $this->assertNull(TypeCaster::castToPhpType('invalid-date', 'DateTimeImmutable'));
    }

    public function testCastToPhpTypeArray()
    {
        $jsonString = '{"a":1,"b":2}';
        $this->assertEquals(['a' => 1, 'b' => 2], TypeCaster::castToPhpType($jsonString, 'array'));

        $this->assertNull(TypeCaster::castToPhpType('not a json string', 'array'));

        $array = ['foo' => 'bar'];
        $this->assertSame($array, TypeCaster::castToPhpType($array, 'array'));
    }

    public function testCastToPhpTypeNullAndUnknown()
    {
        $this->assertNull(TypeCaster::castToPhpType(null, 'int'));
        $this->assertSame('val', TypeCaster::castToPhpType('val', null));
        $this->assertSame(123, TypeCaster::castToPhpType(123, 'unknown_type'));
    }

    public function testCastToDatabaseDateTime()
    {
        $dt = new DateTimeImmutable('2023-01-01 10:00:00');
        $this->assertEquals('2023-01-01 10:00:00', TypeCaster::castToDatabase($dt));
    }

    public function testCastToDatabaseArray()
    {
        $array = ['a' => 1, 'b' => 'test'];
        $this->assertEquals('{"a":1,"b":"test"}', TypeCaster::castToDatabase($array));
    }

    public function testCastToDatabaseBool()
    {
        $this->assertSame(1, TypeCaster::castToDatabase(true));
        $this->assertSame(0, TypeCaster::castToDatabase(false));
    }

    public function testCastToDatabaseNullAndScalars()
    {
        $this->assertNull(TypeCaster::castToDatabase(null));
        $this->assertSame(123, TypeCaster::castToDatabase(123));
        $this->assertSame('hello', TypeCaster::castToDatabase('hello'));
        $this->assertSame(12.34, TypeCaster::castToDatabase(12.34));
    }
}
