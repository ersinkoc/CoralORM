<?php
declare(strict_types=1);

namespace CoralORM\Migration;

use CoralORM\Connection;
use CoralORM\QueryBuilder; // For interacting with migrations table
use PDOException;
use FilesystemIterator;
use DateTime;
use Throwable; // For catching general errors in up/down

class Migrator
{
    protected Connection $connection;
    protected string $migrationsPath;
    protected string $migrationsTable = 'migrations'; // Default table name
    // Define a namespace where migration classes are expected to be found.
    // This should match the namespace used in generated migration files.
    protected string $migrationNamespace = 'CoralORM\\Migration\\Database\\';

    public function __construct(Connection $connection, string $migrationsPath)
    {
        $this->connection = $connection;
        $this->migrationsPath = rtrim($migrationsPath, '/');
        $this->ensureMigrationsTableExists();
    }

    protected function ensureMigrationsTableExists(): void
    {
        try {
            // Check if table exists by trying to select from it. This is a common approach.
            // A more specific way is to query INFORMATION_SCHEMA.TABLES, but that's DB-specific.
            (new QueryBuilder($this->connection))
                ->select('1')
                ->from($this->migrationsTable)
                ->limit(1)
                ->fetch(); // Will throw PDOException if table doesn't exist (depending on PDO error mode)
        } catch (PDOException $e) {
            // Table likely doesn't exist. Create it.
            // This error check is basic. A more specific error code check (e.g., '42S02' for table not found)
            // would be more robust than catching any PDOException.
            echo "Migrations table '{$this->migrationsTable}' not found, creating it." . PHP_EOL;
            $schemaBuilder = new \CoralORM\Migration\SchemaBuilder($this->connection);
            $schemaBuilder->createTable($this->migrationsTable, function(\CoralORM\Migration\SchemaBuilder $table) {
                $table->id(); // Standard auto-incrementing PK 'id'
                $table->string('migration')->unique(); // Migration file name (without .php)
                $table->integer('batch');
                // Changed to string to store full timestamp from PHP, not DB default.
                $table->string('executed_at'); // DATETIME or TIMESTAMP as string 'YYYY-MM-DD HH:MM:SS'
            });
            echo "Migrations table '{$this->migrationsTable}' created." . PHP_EOL;
        }
    }

    public function getAllMigrationFiles(): array
    {
        $files = [];
        if (!is_dir($this->migrationsPath)) {
            // echo "Migrations directory not found: {$this->migrationsPath}\n";
            return [];
        }
        $iterator = new FilesystemIterator($this->migrationsPath, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile() && $fileinfo->getExtension() === 'php') {
                $migrationName = basename($fileinfo->getFilename(), '.php');
                // Validate format YYYYMMDDHHMMSS_MigrationName
                if (preg_match('/^\d{14}_\w+$/', $migrationName)) {
                    $files[] = $migrationName;
                }
            }
        }
        sort($files); // Ensure chronological order
        return $files;
    }

    public function getExecutedMigrations(): array
    {
        $qb = new QueryBuilder($this->connection);
        try {
            // Fetch all details for status command
            $results = $qb->select('migration', 'batch', 'executed_at')
                          ->from($this->migrationsTable)
                          ->orderBy('migration', 'ASC')
                          ->fetchAll();
            // Return associative array with migration name as key for easy lookup
            $detailedMigrations = [];
            foreach ($results as $row) {
                $detailedMigrations[$row['migration']] = $row;
            }
            return $detailedMigrations;
            // Old: return array_column($results, 'migration');
        } catch (PDOException $e) {
            // This might happen if the table doesn't exist, though ensureMigrationsTableExists should handle it.
            // If ensureMigrationsTableExists failed silently, this will fail too.
            // error_log("Error fetching executed migrations: " . $e->getMessage());
            return []; // Return empty if table doesn't exist or other error
        }
    }

    public function getPendingMigrations(): array
    {
        $allFiles = $this->getAllMigrationFiles();
        $executedDetails = $this->getExecutedMigrations(); // Now returns details
        $executedNames = array_keys($executedDetails);
        return array_diff($allFiles, $executedNames);
    }

    protected function getNextBatchNumber(): int
    {
        $qb = new QueryBuilder($this->connection);
        try {
            $result = $qb->select('MAX(batch) as max_batch')->from($this->migrationsTable)->fetch();
            return ($result && $result['max_batch'] !== null) ? (int)$result['max_batch'] + 1 : 1;
        } catch (PDOException $e) {
            //  error_log("Error fetching next batch number (assuming 1): " . $e->getMessage());
             return 1;
        }
    }

    /**
     * Instantiates a migration class from its name.
     * Assumes migrationName is like YYYYMMDDHHMMSS_ClassName
     * And class to instantiate is ClassName_YYYYMMDDHHMMSS within the defined migration namespace.
     */
    protected function resolveMigrationClass(string $migrationName): ?AbstractMigration
    {
        $filePath = $this->migrationsPath . '/' . $migrationName . '.php';
        if (!file_exists($filePath)) {
            error_log("Migration file not found: {$filePath}");
            return null;
        }
        require_once $filePath;

        preg_match('/^(\d{14})_(\w+)$/', $migrationName, $matches);
        if (!$matches) {
             error_log("Invalid migration name format for class resolution: {$migrationName}. Expected YYYYMMDDHHMMSS_ClassName.");
             return null;
        }
        // Class name is ClassName_YYYYMMDDHHMMSS
        $classNamePart = $matches[2];
        $timestampPart = $matches[1];
        $classNameToInstantiate = $classNamePart . '_' . $timestampPart;

        // Prepend the defined namespace
        $fullClassName = rtrim($this->migrationNamespace, '\\') . '\\' . $classNameToInstantiate;

        if (!class_exists($fullClassName)) {
            error_log("Migration class not found after require: {$fullClassName}. Searched in {$filePath}.");
            return null;
        }

        $migrationInstance = new $fullClassName();
        if (!$migrationInstance instanceof AbstractMigration) {
             error_log("Migration class {$fullClassName} must extend AbstractMigration.");
             return null;
        }
        return $migrationInstance;
    }

    public function runUp(string $migrationName): bool
    {
        $migrationInstance = $this->resolveMigrationClass($migrationName);
        if (!$migrationInstance) {
            return false;
        }

        $schemaBuilder = new \CoralORM\Migration\SchemaBuilder($this->connection);
        try {
            echo "Running up: {$migrationName}" . PHP_EOL;
            $migrationInstance->up($schemaBuilder);
            // Log migration before postUp, so if postUp fails, migration is still marked as run.
            // Alternatively, log after postUp if postUp failure should mean migration failure.
            // For now, let's assume up() is the critical part for schema, postUp is auxiliary.
            $currentBatch = $this->getNextBatchNumber(); // Determine batch number before logging
            $this->logMigration($migrationName, $currentBatch);
            echo "Finished up: {$migrationName}" . PHP_EOL;

            // Call postUp
            try {
                echo "Running postUp for: {$migrationName}" . PHP_EOL;
                $migrationInstance->postUp($this->connection);
                echo "Finished postUp for: {$migrationName}" . PHP_EOL;
            } catch (Throwable $pe) {
                // Log error in postUp but don't necessarily roll back the main 'up' migration.
                // This depends on desired transactional behavior.
                error_log("Error running postUp for migration {$migrationName}: " . $pe->getMessage() . " in " . $pe->getFile() . ":" . $pe->getLine());
                // Optionally, this could trigger a warning or a specific compensation logic.
            }
            return true;
        } catch (Throwable $e) { // Catch any error/exception
            error_log("Error running migration {$migrationName} up: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            // Optionally, re-throw or handle specific exceptions if needed for transactions
            return false;
        }
    }

    public function runDown(string $migrationName): bool
    {
        $migrationInstance = $this->resolveMigrationClass($migrationName);
        if (!$migrationInstance) {
            return false;
        }

        $schemaBuilder = new \CoralORM\Migration\SchemaBuilder($this->connection);
        try {
            echo "Running down: {$migrationName}" . PHP_EOL;
            $migrationInstance->down($schemaBuilder);
            $this->unlogMigration($migrationName);
            echo "Finished down: {$migrationName}" . PHP_EOL;
            return true;
        } catch (Throwable $e) {
            error_log("Error running migration {$migrationName} down: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return false;
        }
    }

    protected function logMigration(string $migrationName, int $batch): void
    {
        $qb = new QueryBuilder($this->connection);
        $qb->insert($this->migrationsTable, [
            'migration' => $migrationName,
            'batch' => $batch,
            'executed_at' => (new DateTime())->format('Y-m-d H:i:s')
        ])->execute(); // Added execute() call, as insert() prepares query
    }

    protected function unlogMigration(string $migrationName): void
    {
        $qb = new QueryBuilder($this->connection);
        // Using QueryBuilder's delete which now correctly builds its SQL and params.
        $qb->delete($this->migrationsTable)
           ->where('migration', '=', $migrationName)
           ->execute(); // execute() for DELETE
    }

    public function getLastBatchMigrations(): array
    {
        $qb = new QueryBuilder($this->connection);
        try {
            $result = $qb->select('MAX(batch) as max_batch')->from($this->migrationsTable)->fetch();
            if ($result && $result['max_batch'] !== null) {
                $maxBatch = (int)$result['max_batch'];
                // Need a new QueryBuilder instance as fetch() resets it.
                $qb = new QueryBuilder($this->connection);
                $migrationsData = $qb->select('migration')
                                 ->from($this->migrationsTable)
                                 ->where('batch', '=', $maxBatch)
                                 ->orderBy('migration', 'DESC') // Rollback in reverse order of execution
                                 ->fetchAll();
                return array_column($migrationsData, 'migration');
            }
        } catch (PDOException $e) {
            // error_log("Error fetching last batch migrations: " . $e->getMessage());
        }
        return [];
    }

    public function getDistinctBatchesDesc(int $limit): array
    {
        $qb = new QueryBuilder($this->connection);
        try {
            // Using raw query part for DISTINCT as QueryBuilder might not support it directly
            // Or QueryBuilder could be enhanced: $qb->select('batch')->distinct()->from(...)
            // For now, we assume QueryBuilder can handle a simple select of a column.
            // We need a way to do SELECT DISTINCT batch ORDER BY batch DESC LIMIT N.
            // QueryBuilder's current select doesn't have distinct.
            // Let's assume a simplified approach or raw SQL for this specific need.
            // If QueryBuilder's select took an array of columns, `['DISTINCT batch']` might work.
            // Or, fetch all batches and unique/sort them in PHP, less efficient for many batches.

            // Simple approach: fetch all batch numbers, sort unique in PHP.
            $allBatchesQuery = $qb->select('batch')
                                  ->from($this->migrationsTable)
                                  ->orderBy('batch', 'DESC') // Get them in DESC order
                                  ->fetchAll();

            if (empty($allBatchesQuery)) {
                return [];
            }

            $distinctBatches = [];
            foreach ($allBatchesQuery as $row) {
                if (!in_array((int)$row['batch'], $distinctBatches)) {
                    $distinctBatches[] = (int)$row['batch'];
                }
            }
            // $distinctBatches are already sorted DESC due to SQL query
            return array_slice($distinctBatches, 0, $limit);

        } catch (PDOException $e) {
            error_log("Error fetching distinct batches: " . $e->getMessage());
        }
        return [];
    }

    public function getMigrationsByBatchNumber(int $batchNumber): array
    {
        $qb = new QueryBuilder($this->connection);
        try {
            $migrations = $qb->select('migration')
                             ->from($this->migrationsTable)
                             ->where('batch', '=', $batchNumber)
                             ->orderBy('migration', 'DESC') // Important: Rollback newest in batch first
                             ->fetchAll();
            return array_column($migrations, 'migration');
        } catch (PDOException $e) {
            error_log("Error fetching migrations for batch {$batchNumber}: " . $e->getMessage());
        }
        return [];
    }
}
