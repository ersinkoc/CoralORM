<?php

declare(strict_types=1);

namespace CoralORM\Mapping;

use Attribute;

/**
 * Annotation to mark a property as non-nullable.
 * The ORM should enforce this before persisting or updating an entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class NotNull
{
    public function __construct()
    {
        // Constructor can be empty for a simple marker annotation
        // Or it could take a custom message, etc.
    }
}
