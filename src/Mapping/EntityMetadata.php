<?php
declare(strict_types=1);
namespace CoralORM\Mapping;

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

    /**
     * @var array<string, array{
     *   type: string, // 'BelongsTo', 'HasOne', 'HasMany', 'ManyToMany'
     *   relatedEntity: class-string,
     *   foreignKey?: ?string, // DB column name on owning side (BelongsTo) or on related side (HasMany, HasOne)
     *   ownerKey?: ?string, // DB column name on related side (PK of related for BelongsTo)
     *   localKey?: ?string, // DB column name on this entity (PK of this for HasMany, HasOne)
     *   joinTableName?: ?string, // For ManyToMany
     *   manyToManyLocalKey?: ?string, // Column in join table referencing this entity's PK (for ManyToMany)
     *   manyToManyForeignKey?: ?string, // Column in join table referencing target entity's PK (for ManyToMany)
     *   propertyName: string // Name of the PHP property holding the relation
     * }>
     */
    public array $relations = [];

    /**
     * Stores validation rules for properties.
     * Example: ['propertyName' => [NotNullObject, LengthObject]]
     * @var array<string, array<object>>
     */
    public array $propertyValidations = [];


    public function __construct(string $className)
    {
        $this->className = $className;
        $this->loadMetadata($className);
    }

    private function loadMetadata(string $className): void
    {
        $reflectionClass = new \ReflectionClass($className);

        // Example: Load table name from Table attribute (assuming Table attribute exists)
        $tableAttributes = $reflectionClass->getAttributes(Table::class);
        if (!empty($tableAttributes)) {
            $tableInstance = $tableAttributes[0]->newInstance();
            $this->tableName = $tableInstance->name;
        } else {
            // Fallback or error if Table attribute is mandatory
            $this->tableName = strtolower(substr($reflectionClass->getShortName(), 0, -0)) . 's'; // Basic guess
        }

        foreach ($reflectionClass->getProperties() as $property) {
            // Load Column metadata (conceptual - assuming similar logic for @ORM\Column)
            $columnAttributes = $property->getAttributes(Column::class);
            if (!empty($columnAttributes)) {
                $columnInstance = $columnAttributes[0]->newInstance();
                $this->columns[$property->getName()] = [
                    'name' => $columnInstance->name ?? $property->getName(),
                    'type' => $columnInstance->type ?? 'string', // Default type
                    'isPrimaryKey' => false, // Placeholder
                    'isCreatedAt' => false,  // Placeholder
                    'isUpdatedAt' => false,  // Placeholder
                    'phpType' => $property->getType() ? $property->getType()->getName() : null,
                    'reflectionProperty' => $property,
                ];
            }

            // Load PrimaryKey metadata
            $pkAttributes = $property->getAttributes(PrimaryKey::class);
            if (!empty($pkAttributes)) {
                $pkInstance = $pkAttributes[0]->newInstance();
                $this->primaryKeyProperty = $property->getName();
                // Assuming column name for PK is same as property name if not specified in @Column
                $this->primaryKeyColumn = $this->columns[$property->getName()]['name'] ?? $property->getName();
                $this->isPrimaryKeyAutoIncrement = $pkInstance->autoIncrement;
                if (isset($this->columns[$property->getName()])) {
                    $this->columns[$property->getName()]['isPrimaryKey'] = true;
                }
            }

            // Load CreatedAt metadata
            $createdAtAttributes = $property->getAttributes(CreatedAt::class);
            if (!empty($createdAtAttributes)) {
                $this->createdAtProperty = $property->getName();
                $this->createdAtColumn = $this->columns[$property->getName()]['name'] ?? $property->getName();
                 if (isset($this->columns[$property->getName()])) {
                    $this->columns[$property->getName()]['isCreatedAt'] = true;
                }
            }

            // Load UpdatedAt metadata
            $updatedAtAttributes = $property->getAttributes(UpdatedAt::class);
            if (!empty($updatedAtAttributes)) {
                $this->updatedAtProperty = $property->getName();
                $this->updatedAtColumn = $this->columns[$property->getName()]['name'] ?? $property->getName();
                if (isset($this->columns[$property->getName()])) {
                    $this->columns[$property->getName()]['isUpdatedAt'] = true;
                }
            }

            // Load NotNull validation metadata
            $notNullAttributes = $property->getAttributes(NotNull::class);
            if (!empty($notNullAttributes)) {
                $this->addPropertyValidation($property->getName(), $notNullAttributes[0]->newInstance());
            }

            // Load Length validation metadata
            $lengthAttributes = $property->getAttributes(Length::class);
            if (!empty($lengthAttributes)) {
                $this->addPropertyValidation($property->getName(), $lengthAttributes[0]->newInstance());
            }

            // --- Load Relation Metadata ---
            // BelongsTo
            $belongsToAttributes = $property->getAttributes(BelongsTo::class);
            if (!empty($belongsToAttributes)) {
                /** @var BelongsTo $relationInstance */
                $relationInstance = $belongsToAttributes[0]->newInstance();
                $this->relations[$property->getName()] = [
                    'type' => 'BelongsTo',
                    'relatedEntity' => $relationInstance->targetEntity,
                    'foreignKey' => $relationInstance->foreignKey, // This is the property name on current entity holding the FK value
                    'ownerKey' => $relationInstance->ownerKey, // This is the column name on the target entity (usually its PK)
                    'propertyName' => $property->getName(),
                ];
            }

            // HasMany
            $hasManyAttributes = $property->getAttributes(HasMany::class);
            if (!empty($hasManyAttributes)) {
                /** @var HasMany $relationInstance */
                $relationInstance = $hasManyAttributes[0]->newInstance();
                $this->relations[$property->getName()] = [
                    'type' => 'HasMany',
                    'relatedEntity' => $relationInstance->targetEntity,
                    'foreignKey' => $relationInstance->foreignKey, // Column name on the related entity that points back to this entity's PK
                    'localKey' => $relationInstance->localKey, // Column name of this entity's PK
                    'propertyName' => $property->getName(),
                ];
            }

            // HasOne
            $hasOneAttributes = $property->getAttributes(HasOne::class);
            if (!empty($hasOneAttributes)) {
                /** @var HasOne $relationInstance */
                $relationInstance = $hasOneAttributes[0]->newInstance();
                $this->relations[$property->getName()] = [
                    'type' => 'HasOne',
                    'relatedEntity' => $relationInstance->targetEntity,
                    'foreignKey' => $relationInstance->foreignKey, // Column name on the related entity that points back to this entity's PK
                    'localKey' => $relationInstance->localKey, // Column name of this entity's PK
                    'propertyName' => $property->getName(),
                ];
            }

            // ManyToMany
            $manyToManyAttributes = $property->getAttributes(ManyToMany::class);
            if (!empty($manyToManyAttributes)) {
                /** @var ManyToMany $relationInstance */
                $relationInstance = $manyToManyAttributes[0]->newInstance();
                $this->relations[$property->getName()] = [
                    'type' => 'ManyToMany',
                    'relatedEntity' => $relationInstance->targetEntity,
                    'joinTableName' => $relationInstance->joinTableName,
                    'manyToManyLocalKey' => $relationInstance->localKeyColumnName,   // e.g., post_id in post_tag
                    'manyToManyForeignKey' => $relationInstance->foreignKeyColumnName, // e.g., tag_id in post_tag
                    'propertyName' => $property->getName(),
                ];
            }
        }
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

    /**
     * Adds a validation rule for a given property.
     *
     * @param string $propertyName The name of the property.
     * @param object $validationAttributeInstance An instance of the validation attribute (e.g., NotNull, Length).
     */
    public function addPropertyValidation(string $propertyName, object $validationAttributeInstance): void
    {
        if (!isset($this->propertyValidations[$propertyName])) {
            $this->propertyValidations[$propertyName] = [];
        }
        $this->propertyValidations[$propertyName][] = $validationAttributeInstance;
    }

    /**
     * Retrieves all validation rules for a specific property.
     *
     * @param string $propertyName The name of the property.
     * @return array<object> An array of validation attribute instances, or an empty array if none.
     */
    public function getPropertyValidations(string $propertyName): array
    {
        return $this->propertyValidations[$propertyName] ?? [];
    }
}
