<?php

declare(strict_types=1);

namespace YourOrm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class HasMany
{
    /**
     * @param string $relatedEntity The fully qualified class name of the related entity.
     * @param string $foreignKey The foreign key column name on the related entity's table
     *                           that points back to this entity's table.
     * @param string|null $localKey The column name on this entity's table that $foreignKey
     *                              on the related entity refers to. Defaults to this entity's
     *                              primary key if null.
     */
    public function __construct(
        public string $relatedEntity,
        public string $foreignKey,
        public ?string $localKey = null
    ) {
    }
}
