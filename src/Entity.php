<?php

declare(strict_types=1);

namespace App;

/**
 * Represents a database entity.
 */
abstract class Entity
{
    protected array $data = [];
    protected array $dirtyData = [];

    /**
     * Entity constructor.
     *
     * @param array<string, mixed> $data Initial data to populate the entity.
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
        // Initially, the entity is considered pristine, so no dirty data.
    }

    /**
     * Magic getter to access entity properties.
     *
     * @param string $name The name of the property.
     * @return mixed The value of the property or null if not set.
     */
    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? ($this->dirtyData[$name] ?? null);
    }

    /**
     * Magic setter to set entity properties.
     *
     * @param string $name The name of the property.
     * @param mixed $value The value to set.
     */
    public function __set(string $name, mixed $value): void
    {
        // Check if the new value is different from the original value (if it exists)
        // or if the property is not in original data (meaning it's a new property)
        if (!array_key_exists($name, $this->data) || $this->data[$name] !== $value) {
            $this->dirtyData[$name] = $value;
        } elseif (array_key_exists($name, $this->dirtyData)) {
            // If the value is set back to its original state, remove it from dirtyData
            unset($this->dirtyData[$name]);
        }
    }

    /**
     * Checks if the entity has been modified.
     *
     * @return bool True if any property has been modified, false otherwise.
     */
    public function isDirty(): bool
    {
        return !empty($this->dirtyData);
    }

    /**
     * Gets an array of modified properties and their new values.
     *
     * @return array<string, mixed> An associative array of dirty properties.
     */
    public function getDirtyData(): array
    {
        return $this->dirtyData;
    }

    /**
     * Marks the entity as not modified by clearing dirty data
     * and updating the original data with the changes.
     */
    public function markAsPristine(): void
    {
        // Merge dirty data into the main data array
        foreach ($this->dirtyData as $key => $value) {
            $this->data[$key] = $value;
        }
        $this->dirtyData = [];
    }

    /**
     * Gets the database table name for this entity.
     *
     * @return string The table name.
     */
    abstract public function getTableName(): string;

    /**
     * Gets the primary key column name for this entity.
     *
     * @return string The primary key column name.
     */
    abstract public function getPrimaryKeyName(): string;
}
