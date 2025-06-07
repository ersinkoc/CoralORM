<?php

declare(strict_types=1);

namespace CoralORM;

use PDOException;
use CoralORM\Mapping\EntityMetadata;
use CoralORM\Entity; // Ensure base Entity is imported for type hinting
use CoralORM\QueryBuilder;
use CoralORM\Util\TypeCaster;

/**
 * Manages persistence for entities.
 *
 * @template T of Entity
 */
class Repository
{
    protected QueryBuilder $qb;
    protected EntityMetadata $metadata;
    protected array $eagerLoad = []; // Stores relations to eager load

    /**
     * Repository constructor.
     *
     * @param Connection $connection The database connection.
     * @param class-string<T> $entityClass The fully qualified class name of the entity.
     */
    public function __construct(
        protected Connection $connection,
        protected string $entityClass
    ) {
        $this->qb = new QueryBuilder($this->connection);
        $this->metadata = $this->entityClass::getMetadata();
    }

    /**
     * Specifies relations to eager load.
     *
     * @param string|array<string> $relations Relation name or array of relation names.
     * @return $this
     */
    public function with(string|array $relations): self
    {
        $this->eagerLoad = is_array($relations) ? $relations : [$relations];
        return $this;
    }

    /**
     * Finds an entity by its primary key.
     *
     * @param int|string $id The primary key value.
     * @return ?T The entity instance or null if not found.
     */
    public function find(int|string $id): ?Entity
    {
        $tableName = $this->metadata->tableName;
        $primaryKeyColumn = $this->metadata->primaryKeyColumn;

        if (!$primaryKeyColumn) {
            throw new \LogicException("Primary key column not defined for entity: {$this->entityClass}");
        }

        $entity = null;
        try {
            $data = $this->qb->select()
                ->from($tableName)
                ->where($primaryKeyColumn, '=', $id)
                ->fetch();

            if ($data) {
                $entityInstance = new $this->entityClass($data);
                if (!empty($this->eagerLoad)) {
                    $this->loadRelations([$entityInstance]);
                }
                $entity = $entityInstance;
            }
        } catch (PDOException $e) {
            error_log("Error in Repository::find for {$this->entityClass} with ID {$id}: " . $e->getMessage());
        } finally {
            $this->eagerLoad = []; // Clear eager load requests for next call
        }
        return $entity;
    }

    /**
     * Retrieves all entities of this type.
     *
     * @return array<T> An array of entity instances.
     */
    public function findAll(): array
    {
        $tableName = $this->metadata->tableName;
        $entities = [];

        try {
            $results = $this->qb->select()
                ->from($tableName)
                ->fetchAll();

            foreach ($results as $data) {
                $entities[] = new $this->entityClass($data);
            }

            if (!empty($entities) && !empty($this->eagerLoad)) {
                $this->loadRelations($entities);
            }
        } catch (PDOException $e) {
            error_log("Error in Repository::findAll for {$this->entityClass}: " . $e->getMessage());
        } finally {
            $this->eagerLoad = [];
        }
        return $entities;
    }

    /**
     * Finds entities matching specific criteria.
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $tableName = $this->metadata->tableName;
        $query = $this->qb->select()->from($tableName);

        foreach ($criteria as $propertyName => $value) {
            $columnName = $this->metadata->getColumnNameForProperty($propertyName);
            if (!$columnName) {
                // Option 1: Skip if not a mapped property
                // error_log("Warning: Property '{$propertyName}' not found in metadata for entity {$this->entityClass} during findBy. Skipping.");
                // continue;
                // Option 2: Throw an exception for stricter handling
                throw new \InvalidArgumentException("Property '{$propertyName}' is not a mapped property of entity {$this->entityClass}.");
            }
            // TODO: Add support for other operators besides '=' if desired, e.g. $value = ['>', 10]
            if (is_array($value)) {
                // Simple IN clause support: $criteria = ['id' => [1, 2, 3]]
                $query->whereIn($columnName, $value);
            } else {
                $query->where($columnName, '=', $value);
            }
        }

        if (!empty($orderBy)) {
            foreach ($orderBy as $propertyName => $direction) {
                $columnName = $this->metadata->getColumnNameForProperty($propertyName);
                if (!$columnName) {
                     throw new \InvalidArgumentException("Property '{$propertyName}' for orderBy is not a mapped property of entity {$this->entityClass}.");
                }
                $query->orderBy($columnName, $direction);
            }
        }
        if ($limit !== null) $query->limit($limit);
        if ($offset !== null) $query->offset($offset);

        $entities = [];
        try {
            $results = $query->fetchAll();
            foreach ($results as $data) {
                $entities[] = new $this->entityClass($data);
            }
            if (!empty($entities) && !empty($this->eagerLoad)) {
                $this->loadRelations($entities);
            }
        } catch (PDOException $e) {
            error_log("Error in Repository::findBy for {$this->entityClass}: " . $e->getMessage());
        } finally {
            $this->eagerLoad = [];
        }
        return $entities;
    }

    /**
     * Finds a single entity matching specific criteria.
     *
     * @param array $criteria Criteria to search by (property_name => value).
     * @param ?array $orderBy Order by criteria (property_name => 'ASC'|'DESC').
     * @return ?T The entity instance or null if not found.
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?Entity
    {
        $results = $this->findBy($criteria, $orderBy, 1, null);
        return $results[0] ?? null;
    }

    /**
     * Loads specified relations for a collection of entities.
     *
     * @param array<Entity> $entities The primary entities.
     */
    protected function loadRelations(array $entities): void
    {
        if (empty($entities) || empty($this->eagerLoad)) {
            return;
        }

        foreach ($this->eagerLoad as $relationName) {
            $relationMeta = $this->metadata->relations[$relationName] ?? null;
            if (!$relationMeta) {
                // Optionally throw an exception or log a warning if relation is not defined
                error_log("Relation '{$relationName}' not defined for entity {$this->entityClass}");
                continue;
            }

            if ($relationMeta['type'] === 'BelongsTo') {
                $this->loadBelongsToRelation($entities, $relationName, $relationMeta);
            } elseif ($relationMeta['type'] === 'HasMany') {
                $this->loadHasManyRelation($entities, $relationName, $relationMeta);
            } elseif ($relationMeta['type'] === 'HasOne') {
                // HasOne is similar to BelongsTo but with keys reversed, or like HasMany with a single result.
                // For now, can be a simplified HasMany or needs specific handling if different logic for setting property.
                // Let's assume it's loaded like HasMany for now, but might need unique result constraint.
                $this->loadHasManyRelation($entities, $relationName, $relationMeta, true); // true for isHasOne
            } elseif ($relationMeta['type'] === 'ManyToMany') {
                $this->loadManyToManyRelation($entities, $relationName, $relationMeta);
            }
            // TODO: Ensure all relation types are covered or throw exception for undefined ones
        }
    }

    /**
     * Loads a BelongsTo relation for a collection of entities.
     *
     * @param array<Entity> $entities
     * @param string $relationName The name of the property on primary entities to set.
     * @param array $relationMeta Metadata for the BelongsTo relation.
     */
    protected function loadBelongsToRelation(array $entities, string $relationName, array $relationMeta): void
    {
        $foreignKeyNameOnOwning = $this->metadata->getColumnNameForProperty($relationMeta['foreignKey']) ?? $relationMeta['foreignKey'];
        $ownerKeyNameOnRelated = $relationMeta['ownerKey']; // This is a DB column name on the related table
        $relatedEntityClass = $relationMeta['relatedEntity'];

        // Collect foreign key values from the primary entities
        $foreignKeyValues = [];
        foreach ($entities as $entity) {
            // The foreign key value is stored on the *current* entity,
            // corresponding to the property that holds the FK value.
            // This property might be different from the relation property name.
            // For example, Post has 'author_id' (FK property) and 'author' (relation property).
            // $relationMeta['foreignKey'] IS the property name of the FK on the current entity.
            $fkValue = $entity->{$relationMeta['foreignKey']}; // Access FK property directly or via __get
            if ($fkValue !== null) {
                $foreignKeyValues[] = $fkValue;
            }
        }

        if (empty($foreignKeyValues)) {
            return;
        }
        $uniqueForeignKeyValues = array_unique($foreignKeyValues);

        // Fetch related entities
        $relatedMetadata = $relatedEntityClass::getMetadata();
        $relatedRepo = new Repository($this->connection, $relatedEntityClass); // Use a new repo for related type

        // The ownerKeyNameOnRelated is the column on the related table to match against (usually its PK)
        $relatedObjects = $relatedRepo->findBy([$ownerKeyNameOnRelated => $uniqueForeignKeyValues]);

        // Map related objects back to primary entities
        $relatedMap = [];
        foreach ($relatedObjects as $relatedObject) {
            // Key the map by the related object's owner key value (e.g., user's ID)
            $ownerKeyValue = $relatedObject->{$relatedMetadata->getPropertyForColumnName($ownerKeyNameOnRelated)};
            $relatedMap[$ownerKeyValue] = $relatedObject;
        }

        foreach ($entities as $entity) {
            $fkValue = $entity->{$relationMeta['foreignKey']};
            if ($fkValue !== null && isset($relatedMap[$fkValue])) {
                // Set the related object instance on the relation property (e.g., $post->author = $userInstance)
                $entity->{$relationName} = $relatedMap[$fkValue]; // Uses Entity::__set
            } else {
                // Ensure the property is set to null if no related object found
                $entity->{$relationName} = null;
            }
        }
    }


    public function save(Entity $entity): bool
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException("Entity must be an instance of {$this->entityClass}");
        }

        $primaryKeyColumn = $this->metadata->primaryKeyColumn;
        $primaryKeyProperty = $this->metadata->primaryKeyProperty;

        if (!$primaryKeyColumn || !$primaryKeyProperty) {
            throw new \LogicException("Primary key not defined for entity: {$this->entityClass}");
        }

        $primaryKeyValue = $entity->getPrimaryKeyValue();
        $isNew = ($primaryKeyValue === null);

        if ($this->metadata->isPrimaryKeyAutoIncrement && $primaryKeyValue !== null) {
             $existing = $this->find($primaryKeyValue);
             $isNew = ($existing === null);
        }

        $entity->touchTimestamps($isNew);

        try {
            $success = false;
            if ($isNew) {
                $dataToInsert = $entity->getAllDataForPersistence();
                if ($this->metadata->isPrimaryKeyAutoIncrement && array_key_exists($primaryKeyColumn, $dataToInsert)) {
                    if ($dataToInsert[$primaryKeyColumn] === null) {
                         unset($dataToInsert[$primaryKeyColumn]);
                    }
                }
                if (empty($dataToInsert) && !$this->metadata->isPrimaryKeyAutoIncrement && !array_key_exists($primaryKeyColumn, $dataToInsert)) {
                    error_log("Save (insert) failed for {$this->entityClass}: no data to insert and not auto-incrementing PK.");
                    return false;
                }
                $success = $this->qb->insert($this->metadata->tableName, $dataToInsert);
                if ($success && $this->metadata->isPrimaryKeyAutoIncrement) {
                    $lastInsertId = $this->connection->getLastInsertId();
                    if ($lastInsertId !== false && $lastInsertId !== "0") {
                        $pkPhpType = $this->metadata->columns[$primaryKeyProperty]['phpType'] ?? 'int';
                        $entity->setPrimaryKeyValue(TypeCaster::castToPhpType($lastInsertId, $pkPhpType));
                    }
                }
            } else {
                if (!$entity->isDirty()) return true;
                $dirtyDataToUpdate = $entity->getDirtyDataForPersistence();
                if (empty($dirtyDataToUpdate)) return true;
                unset($dirtyDataToUpdate[$primaryKeyColumn]);
                if (empty($dirtyDataToUpdate)) return true;

                $success = $this->qb->update($this->metadata->tableName, $dirtyDataToUpdate)
                    ->where($primaryKeyColumn, '=', $primaryKeyValue)
                    ->execute();
            }

            if ($success) {
                $entity->markAsPristine();
                return true;
            }
            return false;
        } catch (PDOException | \LogicException $e) {
            error_log("Error in Repository::save for {$this->entityClass}: " . $e->getMessage());
            return false;
        }
    }

    public function delete(Entity $entity): bool
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException("Entity must be an instance of {$this->entityClass}");
        }

        $primaryKeyColumn = $this->metadata->primaryKeyColumn;
        $primaryKeyValue = $entity->getPrimaryKeyValue();

        if ($primaryKeyValue === null) {
            error_log("Delete failed for {$this->entityClass}: entity has no primary key value.");
            return false;
        }
        if (!$primaryKeyColumn) {
             throw new \LogicException("Primary key column not defined for entity: {$this->entityClass}");
        }

        try {
            return $this->qb->delete($this->metadata->tableName)
                ->where($primaryKeyColumn, '=', $primaryKeyValue)
                ->execute();
        } catch (PDOException | \LogicException $e) {
            error_log("Error in Repository::delete for {$this->entityClass} with PK {$primaryKeyValue}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Loads a HasMany or HasOne relation for a collection of entities.
     *
     * @param array<Entity> $entities
     * @param string $relationName The name of the property on primary entities to set.
     * @param array $relationMeta Metadata for the HasMany/HasOne relation.
     * @param bool $isHasOne Whether this is a HasOne relation (expects single related entity).
     */
    protected function loadHasManyRelation(array $entities, string $relationName, array $relationMeta, bool $isHasOne = false): void
    {
        $localKeyProperty = $this->metadata->getPropertyForColumnName($relationMeta['localKey']); // Property name for PK on this entity
        if (!$localKeyProperty) {
             error_log("Local key property for relation '{$relationName}' not found via column '{$relationMeta['localKey']}' on {$this->entityClass}.");
             return;
        }

        $foreignKeyOnRelated = $relationMeta['foreignKey']; // DB Column name on related table that points to this entity's localKey
        $relatedEntityClass = $relationMeta['relatedEntity'];
        /** @var EntityMetadata $relatedMetadata */
        $relatedMetadata = $relatedEntityClass::getMetadata();

        $localKeyValues = [];
        foreach ($entities as $entity) {
            $val = $entity->{$localKeyProperty}; // e.g., $post->id
            if ($val !== null) {
                $localKeyValues[] = $val;
            }
        }

        if (empty($localKeyValues)) {
            return;
        }
        $uniqueLocalKeyValues = array_unique($localKeyValues);

        $relatedRepo = new Repository($this->connection, $relatedEntityClass);
        // Fetch related entities where their foreign key matches our local key values
        $relatedObjects = $relatedRepo->findBy([$relatedMetadata->getPropertyForColumnName($foreignKeyOnRelated) => $uniqueLocalKeyValues]);

        // Map related objects back to primary entities
        $relatedMap = [];
        foreach ($relatedObjects as $relatedObject) {
            // The FK value on the related object tells us which parent it belongs to
            $fkValueOnRelated = $relatedObject->{$relatedMetadata->getPropertyForColumnName($foreignKeyOnRelated)};
            if ($isHasOne) {
                $relatedMap[$fkValueOnRelated] = $relatedObject;
            } else {
                if (!isset($relatedMap[$fkValueOnRelated])) {
                    $relatedMap[$fkValueOnRelated] = [];
                }
                $relatedMap[$fkValueOnRelated][] = $relatedObject;
            }
        }

        foreach ($entities as $entity) {
            $localVal = $entity->{$localKeyProperty};
            if ($localVal !== null && isset($relatedMap[$localVal])) {
                $entity->{$relationName} = $relatedMap[$localVal]; // Assign collection or single object
            } else {
                // Ensure the property is set to an empty array for HasMany or null for HasOne if no related found
                $entity->{$relationName} = $isHasOne ? null : [];
            }
        }
    }

    /**
     * Loads a ManyToMany relation for a collection of entities.
     *
     * @param array<Entity> $entities
     * @param string $relationName The name of the property on primary entities to set.
     * @param array $relationMeta Metadata for the ManyToMany relation.
     */
    protected function loadManyToManyRelation(array $entities, string $relationName, array $relationMeta): void
    {
        $localKeyProperty = $this->metadata->primaryKeyProperty; // Property name for PK on this entity (e.g. 'id')
         if (!$localKeyProperty) {
            error_log("Primary key property not defined for entity {$this->entityClass}, required for ManyToMany relation '{$relationName}'.");
            return;
        }
        $localKeyColumnOnThis = $this->metadata->primaryKeyColumn; // DB Column name for PK on this entity (e.g. 'id')
        if (!$localKeyColumnOnThis) {
            error_log("Primary key column not defined for entity {$this->entityClass}, required for ManyToMany relation '{$relationName}'.");
            return;
        }


        $joinTableName = $relationMeta['joinTableName'];
        $joinLocalKey = $relationMeta['manyToManyLocalKey']; // Column in join table for this entity's ID (e.g., post_id)
        $joinForeignKey = $relationMeta['manyToManyForeignKey']; // Column in join table for related entity's ID (e.g., tag_id)

        $relatedEntityClass = $relationMeta['relatedEntity'];
        /** @var EntityMetadata $relatedMetadata */
        $relatedMetadata = $relatedEntityClass::getMetadata();
        $relatedPrimaryKeyProperty = $relatedMetadata->primaryKeyProperty; // Property name for PK on related entity
        $relatedPrimaryKeyColumn = $relatedMetadata->primaryKeyColumn; // DB Column name for PK on related entity

        if (!$relatedPrimaryKeyProperty || !$relatedPrimaryKeyColumn) {
            error_log("Primary key not defined for related entity {$relatedEntityClass}, required for ManyToMany relation '{$relationName}'.");
            return;
        }

        // 1. Collect PKs of the current entities
        $localPrimaryKeys = [];
        foreach ($entities as $entity) {
            $pkValue = $entity->{$localKeyProperty};
            if ($pkValue !== null) {
                $localPrimaryKeys[] = $pkValue;
            }
        }

        if (empty($localPrimaryKeys)) {
            foreach ($entities as $entity) { $entity->{$relationName} = []; } // Initialize with empty array
            return;
        }
        $uniqueLocalPrimaryKeys = array_unique($localPrimaryKeys);

        // 2. Query the join table for related entity IDs
        // SELECT local_key_column, foreign_key_column FROM join_table WHERE local_key_column IN (...)
        $joinQuery = new QueryBuilder($this->connection);
        $joinResults = $joinQuery->select([$joinLocalKey, $joinForeignKey])
            ->from($joinTableName)
            ->whereIn($joinLocalKey, $uniqueLocalPrimaryKeys)
            ->fetchAll();

        if (empty($joinResults)) {
            foreach ($entities as $entity) { $entity->{$relationName} = []; }
            return;
        }

        // 3. Collect all unique related entity IDs from the join table results
        $relatedForeignKeyValues = [];
        // Map: localPKValue => [relatedFKValue1, relatedFKValue2, ...]
        $localToForeignKeysMap = [];
        foreach ($joinResults as $row) {
            $localPkVal = $row[$joinLocalKey];
            $foreignPkVal = $row[$joinForeignKey];
            $relatedForeignKeyValues[] = $foreignPkVal;
            if (!isset($localToForeignKeysMap[$localPkVal])) {
                $localToForeignKeysMap[$localPkVal] = [];
            }
            $localToForeignKeysMap[$localPkVal][] = $foreignPkVal;
        }
        $uniqueRelatedForeignKeyValues = array_unique($relatedForeignKeyValues);

        if (empty($uniqueRelatedForeignKeyValues)) {
             foreach ($entities as $entity) { $entity->{$relationName} = []; }
             return;
        }

        // 4. Fetch all related entities
        $relatedRepo = new Repository($this->connection, $relatedEntityClass);
        // Find related entities where their PK is in the list of $uniqueRelatedForeignKeyValues
        $relatedObjects = $relatedRepo->findBy([$relatedPrimaryKeyProperty => $uniqueRelatedForeignKeyValues]);


        // 5. Map related objects back to their parent entities
        // Create a map of related_pk_value => related_object_instance for quick lookup
        $relatedObjectsMap = [];
        foreach ($relatedObjects as $relatedObject) {
            $relatedObjectsMap[$relatedObject->{$relatedPrimaryKeyProperty}] = $relatedObject;
        }

        foreach ($entities as $entity) {
            $entityLocalPkValue = $entity->{$localKeyProperty};
            $collection = [];
            if (isset($localToForeignKeysMap[$entityLocalPkValue])) {
                foreach ($localToForeignKeysMap[$entityLocalPkValue] as $relatedFkValue) {
                    if (isset($relatedObjectsMap[$relatedFkValue])) {
                        $collection[] = $relatedObjectsMap[$relatedFkValue];
                    }
                }
            }
            $entity->{$relationName} = $collection; // Assign the collection of related entities
        }
    }
}
