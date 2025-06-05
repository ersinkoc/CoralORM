<?php

declare(strict_types=1);

namespace YourOrm;

use YourOrm\Mapping\EntityMetadata;
use YourOrm\Mapping\Table;
use YourOrm\Mapping\Column;
use YourOrm\Mapping\PrimaryKey;
use YourOrm\Mapping\CreatedAt;
use YourOrm\Mapping\UpdatedAt;
use YourOrm\Mapping\BelongsTo; // Added for relationship
// HasOne, HasMany would be added here if processing them too
use YourOrm\Util\TypeCaster;
use ReflectionClass;
use ReflectionProperty;
use DateTimeImmutable;
use Exception; // For default table name generation if class name is invalid

/**
 * Represents a database entity.
 */
abstract class Entity
{
    protected array $data = []; // Holds original data from DB or initial state
    protected array $dirtyData = []; // Holds modified properties [propertyName => newValue]

    private static array $entityMetadataCache = [];

    /**
     * Entity constructor.
     *
     * @param array<string, mixed> $data Initial data to populate the entity, keys are DB column names.
     */
    public function __construct(array $data = [])
    {
        $metadata = static::getMetadata();
        foreach ($data as $columnName => $value) {
            $propertyName = $metadata->getPropertyForColumnName($columnName);
            if ($propertyName) {
                // Use __set to ensure type casting and consistent population
                $this->__set($propertyName, $value);
            }
        }
        // After initial population from DB data, the entity is considered pristine.
        $this->markAsPristine();
    }

    /**
     * Magic getter to access entity properties.
     *
     * @param string $name The name of the property.
     * @return mixed The value of the property or null if not set.
     */
    public function __get(string $name): mixed
    {
        $metadata = static::getMetadata(); // Ensure metadata is loaded
        $columnInfo = $metadata->columns[$name] ?? null;

        $valueToReturn = null;

        if (array_key_exists($name, $this->dirtyData)) {
            $valueToReturn = $this->dirtyData[$name];
        } elseif (array_key_exists($name, $this->data)) {
            $valueToReturn = $this->data[$name];
        } elseif (property_exists($this, $name) && isset($this->{$name})) {
            // This case handles public properties that might have default values
            // and haven't been explicitly set via __set yet.
            $valueToReturn = $this->{$name};
            // If it's a mapped property, ensure its type is consistent on first access through __get
            if ($columnInfo && $columnInfo['phpType']) {
                 $valueToReturn = TypeCaster::castToPhpType($valueToReturn, $columnInfo['phpType']);
            }
        }

        return $valueToReturn;
    }

    /**
     * Magic setter to set entity properties.
     *
     * @param string $name The name of the property.
     * @param mixed $value The value to set.
     */
    public function __set(string $name, mixed $value): void
    {
        $metadata = static::getMetadata();
        $columnInfo = $metadata->columns[$name] ?? null;
        $relationInfo = $metadata->relations[$name] ?? null;

        $castedValue = $value;
        // Only apply scalar type casting if it's a column and not a defined relation object property
        if ($columnInfo && $columnInfo['phpType'] && !$relationInfo) {
            $castedValue = TypeCaster::castToPhpType($value, $columnInfo['phpType']);
        } elseif ($relationInfo && is_object($value)) {
            // If it's a relation property and we're setting an object, keep it as is.
            // Type check against $relationInfo['relatedEntity'] could be done here if strict.
            $castedValue = $value;
        } elseif ($relationInfo && $value === null) {
            // Allowing null to be set for a relation
            $castedValue = null;
        }
        // If $relationInfo is set but $value is not an object (and not null),
        // it might be an FK value being set before hydration, or an error.
        // For now, this logic assumes direct object assignment for relations.

        // Update public property if it exists
        if (property_exists($this, $name)) {
            $this->{$name} = $castedValue;
        }

        // Handle mapped properties for dirty tracking
        if ($columnInfo) {
            $currentOriginalValue = $this->data[$name] ?? null;
            // If the property is not in original data OR if the new casted value is different from original
            if (!array_key_exists($name, $this->data) || $this->valuesAreDifferent($currentOriginalValue, $castedValue, $columnInfo['phpType'])) {
                $this->dirtyData[$name] = $castedValue;
            } elseif (array_key_exists($name, $this->dirtyData)) {
                // If value is set back to its original state, remove from dirtyData
                unset($this->dirtyData[$name]);
            }
        }
    }

    private function valuesAreDifferent(mixed $original, mixed $new, ?string $phpType): bool
    {
        if ($phpType === DateTimeImmutable::class || $phpType === \DateTime::class) {
            if ($original instanceof DateTimeInterface && $new instanceof DateTimeInterface) {
                return $original != $new;
            }
             // If types changed (e.g. from string to DateTimeImmutable after casting)
            if (is_object($original) !== is_object($new)) return true;
            if ($original === null && $new === null) return false;
            if ($original === null || $new === null) return true;
            // Fallback for other types or if one is not an object
            return $original !== $new;
        }
        if($phpType === 'bool' || $phpType === 'boolean') {
            return (bool)$original !== (bool)$new;
        }
        // Strict comparison for other types
        return $original !== $new;
    }


    public function isDirty(): bool
    {
        return !empty($this->dirtyData);
    }

    public function getDirtyProperties(): array // Returns [propertyName => value]
    {
        return $this->dirtyData;
    }

    public function getDirtyDataForPersistence(): array
    {
        $metadata = static::getMetadata();
        $dbDirtyData = [];
        foreach ($this->dirtyData as $propertyName => $value) {
            $columnName = $metadata->getColumnNameForProperty($propertyName);
            $columnInfo = $metadata->columns[$propertyName] ?? null;
            $phpType = $columnInfo['phpType'] ?? null;
            if ($columnName) {
                $dbDirtyData[$columnName] = TypeCaster::castToDatabase($value, $phpType);
            }
        }
        return $dbDirtyData;
    }

    public function markAsPristine(): void
    {
        foreach ($this->dirtyData as $propertyName => $value) {
            $this->data[$propertyName] = $value;
            if (property_exists($this, $propertyName)) {
                $this->{$propertyName} = $value;
            }
        }
        $this->dirtyData = [];
    }

    public function toArray(): array
    {
        $metadata = static::getMetadata();
        $arrayData = [];

        foreach ($metadata->columns as $propertyName => $columnInfo) {
            // Use __get to ensure consistent value retrieval (e.g. default public props)
            $value = $this->__get($propertyName);
            // For array representation, we might want to cast DateTime to string by default
            if ($value instanceof DateTimeInterface) {
                $arrayData[$columnInfo['name']] = $value->format('Y-m-d H:i:s');
            } else {
                $arrayData[$columnInfo['name']] = $value;
            }
        }
        return $arrayData;
    }

    public function getAllDataForPersistence(): array
    {
        $metadata = static::getMetadata();
        $allData = [];
        foreach ($metadata->columns as $propertyName => $columnInfo) {
            $currentValue = $this->__get($propertyName);
            // Include property if it's dirty, or if it's a primary key (even if null, DB might gen),
            // or if it's a new entity and the value is not null (to insert initial values).
            // This logic can be tricky. For INSERT, we generally want all explicitly set values.
            // For now, let's include all mapped columns that are not null, plus PK if it's null.
            // This is a simplification; a more robust approach might distinguish new vs existing entities better.
            if ($currentValue !== null || $columnInfo['isPrimaryKey']) {
                 $allData[$columnInfo['name']] = TypeCaster::castToDatabase($currentValue, $columnInfo['phpType']);
            }
        }
        return $allData;
    }

    public function touchTimestamps(bool $isNew = false): void
    {
        $metadata = static::getMetadata();
        // Do not create a new DateTimeImmutable if the value is already set (e.g. by user)
        // unless strict control over timestamps is desired.
        // The current logic in __set and valuesAreDifferent should handle comparison correctly.

        if ($isNew && $metadata->createdAtProperty) {
             // Only set if not already set or if forced
            if ($this->__get($metadata->createdAtProperty) === null) {
                $this->__set($metadata->createdAtProperty, new DateTimeImmutable());
            }
        }

        if ($metadata->updatedAtProperty) {
            // Always set updatedAt on touch, unless specific logic dictates otherwise
            $this->__set($metadata->updatedAtProperty, new DateTimeImmutable());
        }
    }

    /** @deprecated Use static::getMetadata()->tableName instead. */
    public function getTableName(): string
    {
        return static::getMetadata()->tableName;
    }

    /** @deprecated Use static::getMetadata()->primaryKeyColumn instead. */
    public function getPrimaryKeyName(): string
    {
        $pkCol = static::getMetadata()->primaryKeyColumn;
        if ($pkCol === null) {
            // This should not happen if getMetadata correctly throws or defaults.
            throw new Exception("Primary key column name not defined for entity " . static::class);
        }
        return $pkCol;
    }

    public function getPrimaryKeyValue(): mixed
    {
        $metadata = static::getMetadata();
        return $metadata->primaryKeyProperty ? $this->__get($metadata->primaryKeyProperty) : null;
    }

    public function setPrimaryKeyValue(mixed $value): void
    {
        $metadata = static::getMetadata();
        if ($metadata->primaryKeyProperty) {
            $this->__set($metadata->primaryKeyProperty, $value);
        }
    }

    public static function getMetadata(string $className = ''): EntityMetadata
    {
        $className = $className ?: static::class;
        if ($className === self::class) { // Avoid issues if called on Entity itself
            throw new Exception("Cannot get metadata for abstract Entity class itself.");
        }

        if (isset(self::$entityMetadataCache[$className])) {
            return self::$entityMetadataCache[$className];
        }

        $metadata = new EntityMetadata($className);
        $reflectionClass = new ReflectionClass($className);

        $tableAttributes = $reflectionClass->getAttributes(Table::class);
        if (empty($tableAttributes)) {
            $shortName = $reflectionClass->getShortName();
            $metadata->tableName = self::toSnakeCase($shortName) . 's';
        } else {
            $tableAttribute = $tableAttributes[0]->newInstance();
            $metadata->tableName = $tableAttribute->name;
        }

        foreach ($reflectionClass->getProperties() as $property) {
            if ($property->isStatic()) continue; // Skip static properties

            $propertyName = $property->getName();
            $isPrimaryKey = false;
            $isCreatedAt = false;
            $isUpdatedAt = false;
            $columnName = self::toSnakeCase($propertyName);
            $phpType = self::getPropertyPhpType($property);

            $columnAttributes = $property->getAttributes(Column::class);
            if (!empty($columnAttributes)) {
                $columnAttribute = $columnAttributes[0]->newInstance();
                $columnName = $columnAttribute->name ?: $columnName;
                $phpType = $columnAttribute->type ?: $phpType;
            }

            $pkAttributes = $property->getAttributes(PrimaryKey::class);
            if (!empty($pkAttributes)) {
                if ($metadata->primaryKeyProperty !== null) throw new Exception("Multiple PrimaryKey attributes for {$className}.");
                $isPrimaryKey = true;
                $pkAttribute = $pkAttributes[0]->newInstance();
                $metadata->primaryKeyProperty = $propertyName;
                $metadata->primaryKeyColumn = $columnName;
                $metadata->isPrimaryKeyAutoIncrement = $pkAttribute->autoIncrement;
            }

            $createdAtAttributes = $property->getAttributes(CreatedAt::class);
            if (!empty($createdAtAttributes)) {
                $isCreatedAt = true;
                $createdAtAttribute = $createdAtAttributes[0]->newInstance();
                $metadata->createdAtProperty = $propertyName;
                $metadata->createdAtColumn = $createdAtAttribute->name ?? $columnName;
                $phpType = $phpType ?? 'DateTimeImmutable';
            }

            $updatedAtAttributes = $property->getAttributes(UpdatedAt::class);
            if (!empty($updatedAtAttributes)) {
                $isUpdatedAt = true;
                $updatedAtAttribute = $updatedAtAttributes[0]->newInstance();
                $metadata->updatedAtProperty = $propertyName;
                $metadata->updatedAtColumn = $updatedAtAttribute->name ?? $columnName;
                $phpType = $phpType ?? 'DateTimeImmutable';
            }

            // Map property if it has Column, PrimaryKey, CreatedAt, or UpdatedAt attribute
            if ($isPrimaryKey || !empty($columnAttributes) || $isCreatedAt || $isUpdatedAt) {
                 $metadata->columns[$propertyName] = [
                    'name' => $columnName,
                    'type' => $phpType, // Original type from reflection or Column attribute
                    'isPrimaryKey' => $isPrimaryKey,
                    'isCreatedAt' => $isCreatedAt,
                    'isUpdatedAt' => $isUpdatedAt,
                    'phpType' => $phpType, // Determined PHP type for casting
                    'reflectionProperty' => $property,
                ];
            }
        }

        if ($metadata->primaryKeyProperty === null) {
            if ($reflectionClass->hasProperty('id')) {
                $idProperty = $reflectionClass->getProperty('id');
                if (!isset($metadata->columns['id'])) { // If 'id' wasn't explicitly mapped by an attribute
                    $metadata->primaryKeyProperty = 'id';
                    $metadata->primaryKeyColumn = self::toSnakeCase('id');
                    $metadata->isPrimaryKeyAutoIncrement = true; // Default assumption
                    $metadata->columns['id'] = [
                        'name' => $metadata->primaryKeyColumn,
                        'type' => self::getPropertyPhpType($idProperty), // Original type
                        'isPrimaryKey' => true, 'isCreatedAt' => false, 'isUpdatedAt' => false,
                        'phpType' => self::getPropertyPhpType($idProperty), // Determined PHP type
                        'reflectionProperty' => $idProperty,
                    ];
                }
            } else {
                 throw new Exception("No PrimaryKey attribute or default 'id' property found for entity {$className}.");
            }
        }

        // After all columns are processed, including PK fallback, parse relations
        foreach ($reflectionClass->getProperties() as $property) {
            if ($property->isStatic()) continue;
            $propertyName = $property->getName();

            // BelongsTo
            $belongsToAttributes = $property->getAttributes(BelongsTo::class);
            if (!empty($belongsToAttributes)) {
                $belongsToAttribute = $belongsToAttributes[0]->newInstance();
                $relatedEntity = $belongsToAttribute->relatedEntity;
                $foreignKey = $belongsToAttribute->foreignKey;
                $ownerKey = $belongsToAttribute->ownerKey;

                if (empty($foreignKey)) {
                    $relatedClassParts = explode('\\', $relatedEntity);
                    $relatedClassNameShort = end($relatedClassParts);
                    $foreignKey = self::toSnakeCase($relatedClassNameShort) . '_id';
                }
                if (empty($ownerKey)) {
                    // Default owner key is the primary key of the related entity.
                    // This requires getting metadata of the related entity.
                    // Be careful of potential circular dependencies if A belongsTo B and B belongsTo A.
                    // For now, assume we can get it or it's 'id' by convention.
                    // A robust solution might involve deferred resolution or a multi-pass metadata load.
                    // Simple default:
                    $relatedMetadata = static::getMetadata($relatedEntity); // Potential recursion
                    $ownerKey = $relatedMetadata->primaryKeyColumn ?? 'id';
                }

                $metadata->relations[$propertyName] = [
                    'type' => 'BelongsTo',
                    'relatedEntity' => $relatedEntity,
                    'foreignKey' => $foreignKey, // DB column name on *this* entity's table
                    'ownerKey' => $ownerKey,     // DB column name on *related* entity's table (its PK)
                    'propertyName' => $propertyName,
                ];
                // Relationship properties should not also be regular columns unless explicitly defined.
                // If a #[Column] attribute also exists on a relation property, it might be an FK column.
                // The current logic maps it as a column if #[Column] is present.
                // This might be okay if FK column ($foreignKey) and relation property ($propertyName) are the same.
                // e.g. public User $author; and #[Column('author_id')] public int $author_id;
                // If $propertyName for BelongsTo is 'author' (an object), its $foreignKey 'author_id' should map to a different property.
                // This implies 'author' relation property should NOT be in $metadata->columns.
                // Let's ensure relation properties are not treated as simple columns unless they also have #[Column]
                // The current column parsing logic already checks for #[Column], PK, CreatedAt, UpdatedAt.
                // If a relation property like 'author' has none of these, it won't be in $metadata->columns, which is correct.
            }
            // TODO: Add HasOne, HasMany parsing here similarly
        }

        self::$entityMetadataCache[$className] = $metadata;
        return $metadata;
    }

    private static function getPropertyPhpType(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();
            if (in_array($typeName, ['int', 'string', 'float', 'array', 'object', 'mixed', DateTimeImmutable::class, \DateTime::class])) {
                 return $typeName;
            }
            if ($typeName === 'bool') return 'boolean'; // Normalize
            if (class_exists($typeName) || interface_exists($typeName)) return $typeName;
        }
        return null;
    }

    private static function toSnakeCase(string $string): string
    {
        if (empty($string)) return '';
        $value = preg_replace('/(?<=\\w)(?=[A-Z])/', "_$1", $string); // Add _ before caps if preceded by a letter/digit
        $value = preg_replace('/\\s+/', '_', $value); // Replace spaces with _
        return strtolower($value);
    }
}
?>
