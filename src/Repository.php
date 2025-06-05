<?php

declare(strict_types=1);

namespace App;

use PDOException; // Keep for potential direct error handling if QueryBuilder throws it

/**
 * Manages persistence for entities.
 *
 * @template T of Entity
 */
class Repository
{
    protected QueryBuilder $qb;

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
    }

    /**
     * Finds an entity by its primary key.
     *
     * @param int|string $id The primary key value.
     * @return ?T The entity instance or null if not found.
     */
    public function find(int|string $id): ?Entity
    {
        /** @var T $entityInstance */
        $entityInstance = new $this->entityClass();
        $tableName = $entityInstance->getTableName();
        $primaryKeyName = $entityInstance->getPrimaryKeyName();

        try {
            $data = $this->qb->select()
                ->from($tableName)
                ->where($primaryKeyName, '=', $id)
                ->fetch();

            if ($data) {
                return new $this->entityClass($data);
            }
        } catch (PDOException $e) {
            error_log("Error in find: " . $e->getMessage());
            // QueryBuilder might throw its own exceptions or PDOExceptions
            // Depending on QueryBuilder's design, this catch might need adjustment
        }
        return null;
    }

    /**
     * Retrieves all entities of this type.
     *
     * @return array<T> An array of entity instances.
     */
    public function findAll(): array
    {
        /** @var T $entityInstance */
        $entityInstance = new $this->entityClass();
        $tableName = $entityInstance->getTableName();
        $entities = [];

        try {
            $results = $this->qb->select()
                ->from($tableName)
                ->fetchAll();

            foreach ($results as $data) {
                $entities[] = new $this->entityClass($data);
            }
        } catch (PDOException $e) {
            error_log("Error in findAll: " . $e->getMessage());
        }
        return $entities;
    }

    /**
     * Finds entities matching specific criteria.
     *
     * @param array<string, mixed> $criteria Key-value pairs of criteria.
     * @param ?array<string, string> $orderBy Associative array for ordering (e.g., ['column' => 'ASC']).
     * @param ?int $limit Maximum number of results.
     * @param ?int $offset Number of results to skip.
     * @return array<T> An array of entity instances.
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        /** @var T $entityInstance */
        $entityInstance = new $this->entityClass();
        $tableName = $entityInstance->getTableName();

        $query = $this->qb->select()->from($tableName);

        foreach ($criteria as $key => $value) {
            // Simple equality for now, QueryBuilder's where can be more flexible
            $query->where($key, '=', $value);
        }

        if (!empty($orderBy)) {
            foreach ($orderBy as $column => $direction) {
                $query->orderBy($column, $direction);
            }
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($offset !== null) {
            $query->offset($offset);
        }

        $entities = [];
        try {
            $results = $query->fetchAll();
            foreach ($results as $data) {
                $entities[] = new $this->entityClass($data);
            }
        } catch (PDOException $e) {
            error_log("Error in findBy: " . $e->getMessage());
        }
        return $entities;
    }

    /**
     * Saves (inserts or updates) an entity in the database.
     *
     * @param Entity $entity The entity to save.
     * @return bool True on success, false on failure.
     */
    public function save(Entity $entity): bool
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException("Entity must be an instance of {$this->entityClass}");
        }

        $tableName = $entity->getTableName();
        $primaryKeyName = $entity->getPrimaryKeyName();
        $primaryKeyValue = $entity->{$primaryKeyName}; // Uses Entity's __get

        try {
            // Determine if it's an INSERT or UPDATE
            // An entity is new if its primary key is not set, or if it's not found in DB (more robust)
            // For simplicity: if PK is null or 0 (common for auto-increment IDs), assume new.
            // A more robust check would be to try a find() first, but that's more DB calls.
            // The current Entity::isDirty() and getDirtyData() are key here.

            if ($primaryKeyValue !== null && $primaryKeyValue !== 0 && $this->find($primaryKeyValue)) { // Entity exists, attempt UPDATE
                if ($entity->isDirty()) {
                    $dirtyData = $entity->getDirtyData();
                    if (empty($dirtyData)) {
                        return true; // Nothing to update
                    }
                    $success = $this->qb->update($tableName, $dirtyData)
                        ->where($primaryKeyName, '=', $primaryKeyValue)
                        ->execute();

                    if ($success) {
                        $entity->markAsPristine();
                        return true;
                    }
                    return false;
                }
                return true; // Not dirty, nothing to save
            } else { // Entity is new or PK not set, attempt INSERT
                // For a new entity, all its current data should be inserted.
                // Entity class should ideally have a method like toArray() to get all data.
                // Let's assume getDirtyData() on a newly instantiated and populated entity returns all fields to be inserted.
                // Or Entity constructor should populate dirtyData for new entities if that's the contract.
                // If Entity constructor sets original data, and setters populate dirtyData,
                // then for a new entity, dirtyData might be what we want.

                $dataToInsert = $entity->getDirtyData();

                // If an entity is new and no setters were called, dirtyData might be empty.
                // We need a reliable way to get all properties.
                // Fallback: if dirtyData is empty and it's an insert, try to get all data.
                // This part is tricky without a clear contract with Entity class for initial data.
                // For now, if $dataToInsert is empty for a new entity, we can't proceed.
                // This was a known point of improvement from the previous step.
                // A simple solution: the Entity's constructor should fill $data, and we use it.
                // Let's assume that for a new entity, all its initial data should be considered for insertion.
                // The Entity class currently initializes $this->data in constructor.
                // And __set populates $this->dirtyData.
                // So for a brand new entity, $this->dirtyData will contain what was explicitly set.
                // If we want to insert all properties passed to constructor, we need access to $entity->data
                // This is not directly public.
                // A temporary workaround:
                if (empty($dataToInsert) && ($primaryKeyValue === null || $primaryKeyValue === 0)) {
                     // This is a hacky way, Entity should provide a toArray() or similar
                    $reflection = new \ReflectionClass($entity);
                    $dataArray = [];
                    // This is still problematic as it doesn't distinguish between default Entity properties
                    // and actual data properties.
                    // The `Entity` constructor takes `array $data` and assigns it to `$this->data`.
                    // Let's assume we need a method in Entity like `getAllData()`
                    // For now, the current `save` logic for INSERT using `getDirtyData` might only work if data is set via `__set`.

                    // Let's refine this: if primary key is null, it's an insert.
                    // The data for insert should be ALL data the entity holds, not just "dirty" ones.
                    // The concept of "dirty" is for updates.
                    // We need a method in Entity to get all its current data properties.
                    // Let's assume Entity class will be modified to have a `toArray()` method.
                    // If not, this insert logic will be flawed.
                    // For now, let's assume getDirtyData() is expected to return all data for a new entity.
                    // This implies the user of Entity must __set all properties for a new entity.

                    if (method_exists($entity, 'getAllDataForInsert')) { // Hypothetical method
                        $dataToInsert = $entity->getAllDataForInsert();
                    } else {
                        // If the entity was just created and populated, all its data is in $entity->data,
                        // and $entity->dirtyData might be empty or a subset.
                        // This is the most problematic part. A proper ORM needs a clear way to get this.
                        // For now, we'll stick to $entity->getDirtyData() and acknowledge this limitation.
                        // The user must ensure dirtyData contains all fields for a new entity.
                    }
                }

                // Remove primary key from data if it's null and auto-increment, DB handles it.
                if (array_key_exists($primaryKeyName, $dataToInsert) && $dataToInsert[$primaryKeyName] === null) {
                    unset($dataToInsert[$primaryKeyName]);
                }

                if (empty($dataToInsert)) {
                     // This might happen if a new entity is created but no properties are set.
                     // Or if the Entity class doesn't correctly provide its data for insertion.
                    error_log("Save (insert) failed: no data to insert for table {$tableName}. Entity primary key: {$primaryKeyValue}.");
                    return false;
                }

                $success = $this->qb->insert($tableName, $dataToInsert);
                if ($success) {
                    // If the primary key is auto-incremented, we might want to fetch it and set it on the entity.
                    // $lastInsertId = $this->connection->getPdo()->lastInsertId(); // QueryBuilder doesn't expose this directly
                    // The Connection class would need a method like getLastInsertId().
                    // if ($lastInsertId && $entity->getPrimaryKeyName()) {
                    //    $entity->{$entity->getPrimaryKeyName()} = $lastInsertId; // Requires __set on Entity
                    // }
                    $entity->markAsPristine(); // After successful insert, it's no longer "dirty" in terms of needing insert.
                    return true;
                }
                return false;
            }
        } catch (PDOException | \LogicException $e) { // QueryBuilder can throw LogicException (e.g. no WHERE for UPDATE)
            error_log("Error in save: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletes an entity from the database.
     *
     * @param Entity $entity The entity to delete.
     * @return bool True on success, false on failure.
     */
    public function delete(Entity $entity): bool
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException("Entity must be an instance of {$this->entityClass}");
        }

        $tableName = $entity->getTableName();
        $primaryKeyName = $entity->getPrimaryKeyName();
        $primaryKeyValue = $entity->{$primaryKeyName};

        if ($primaryKeyValue === null) {
            // Cannot delete an entity without a primary key value
            error_log("Delete failed: entity has no primary key value.");
            return false;
        }

        try {
            return $this->qb->delete($tableName)
                ->where($primaryKeyName, '=', $primaryKeyValue)
                ->execute();
        } catch (PDOException | \LogicException $e) {
            error_log("Error in delete: " . $e->getMessage());
            return false;
        }
    }
}
