<?php
declare(strict_types=1);
namespace CoralORM\Mapping;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class UpdatedAt
{
    public function __construct(public ?string $name = null) {} // DB column name
}
