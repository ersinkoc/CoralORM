<?php
declare(strict_types=1);
namespace YourOrm\Mapping;

class EntityMetadata
{
    public string $tableName;
    public string $className;
    /** @var array<string, array{name: string, type: ?string, isPrimaryKey: bool, isCreatedAt: bool, isUpdatedAt: bool, phpType: ?string, reflectionProperty: \ReflectionProperty}> */
    public array $columns = []; // PropertyName => [dbName, type, isPk, isCreatedAt, isUpdatedAt, reflectionProperty]
    public ?string $primaryKeyProperty = null; // PHP Property name of the PK
    public ?string $primaryKeyColumn = null; // DB Column name of the PK
    public bool $isPrimaryKeyAutoIncrement = true;
    public ?string $createdAtProperty = null; // PHP property name
    public ?string $createdAtColumn = null; // DB column name
    public ?string $updatedAtProperty = null; // PHP property name
    public ?string $updatedAtColumn = null; // DB column name

    /** @var array<string, array{type: string, relatedEntity: string, foreignKey: ?string, ownerKey: ?string, localKey: ?string, propertyName: string}> */
    public array $relations = []; // PropertyName => [type: 'BelongsTo'|'HasOne'|'HasMany', relatedEntity, foreignKey, ownerKey/localKey]


    public function __construct(string $className)
    {
        $this->className = $className;
    }

    public function getColumnNameForProperty(string $propertyName): ?string
    {
        return $this->columns[$propertyName]['name'] ?? null;
    }

    public function getPropertyForColumnName(string $columnName): ?string
    {
        foreach ($this->columns as $propName => $details) {
            if ($details['name'] === $columnName) {
                return $propName;
            }
        }
        return null;
    }
}
