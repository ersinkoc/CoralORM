<?php

declare(strict_types=1);

namespace YourOrm;

use PDOException;
use YourOrm\Mapping\EntityMetadata;
use YourOrm\Entity; // Ensure base Entity is imported for type hinting

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

        foreach ($criteria as $dbColumnName => $value) {
            $query->where($dbColumnName, '=', $value);
        }
        if (!empty($orderBy)) {
            foreach ($orderBy as $dbColumnName => $direction) {
                $query->orderBy($dbColumnName, $direction);
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
            }
            // TODO: Implement HasOne, HasMany loading
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
}
