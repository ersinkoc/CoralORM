<?php
declare(strict_types=1);
namespace CoralORM\Mapping;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOne
{
    public function __construct(
        public string $relatedEntity, // FQCN
        public ?string $foreignKey = null, // Column name on related entity's table
        public ?string $localKey = null     // Column name on current entity's table (its PK)
    ) {}
}
