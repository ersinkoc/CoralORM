<?php
declare(strict_types=1);
namespace CoralORM\Mapping;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public ?string $name = null, // DB column name, defaults to property name
        public ?string $type = null, // PHP type for casting e.g. 'int', 'string', 'bool', 'DateTimeImmutable', 'array'
        // public ?int $length = null, // Primarily for schema, can be ignored by ORM runtime for now
        public ?string $castWith = null // FQCN of a custom caster class/method (advanced)
    ) {}
}
