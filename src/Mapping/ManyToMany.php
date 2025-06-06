<?php

declare(strict_types=1);

namespace YourOrm\Mapping;

use Attribute;
use InvalidArgumentException;

/**
 * Defines a many-to-many relationship between two entities.
 * This attribute should be placed on a property that will hold an array or Collection of related entities.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToMany
{
    /**
     * The fully qualified class name of the target entity.
     * @var class-string
     */
    public string $targetEntity;

    /**
     * The name of the join table (intermediate table).
     */
    public string $joinTableName;

    /**
     * The column name in the join table that references the primary key of the current (owning) entity.
     * For example, if this entity is Post and related is Tag, and join table is post_tag,
     * this would be 'post_id'.
     */
    public string $localKeyColumnName;

    /**
     * The column name in the join table that references the primary key of the target (foreign) entity.
     * For example, if this entity is Post and related is Tag, and join table is post_tag,
     * this would be 'tag_id'.
     */
    public string $foreignKeyColumnName;

    /**
     * Constructor for ManyToMany attribute.
     *
     * @param class-string $targetEntity The fully qualified class name of the target entity.
     * @param string $joinTableName The name of the join table.
     * @param string $localKeyColumnName Column in join table referencing this entity's PK.
     * @param string $foreignKeyColumnName Column in join table referencing target entity's PK.
     */
    public function __construct(
        string $targetEntity,
        string $joinTableName,
        string $localKeyColumnName,
        string $foreignKeyColumnName
    ) {
        if (!class_exists($targetEntity)) {
            throw new InvalidArgumentException("Target entity class '{$targetEntity}' does not exist.");
        }
        if (empty($joinTableName)) {
            throw new InvalidArgumentException("Join table name cannot be empty.");
        }
        if (empty($localKeyColumnName)) {
            throw new InvalidArgumentException("Local key column name in join table cannot be empty.");
        }
        if (empty($foreignKeyColumnName)) {
            throw new InvalidArgumentException("Foreign key column name in join table cannot be empty.");
        }

        $this->targetEntity = $targetEntity;
        $this->joinTableName = $joinTableName;
        $this->localKeyColumnName = $localKeyColumnName;
        $this->foreignKeyColumnName = $foreignKeyColumnName;
    }
}
