<?php

declare(strict_types=1);

// ORM CLI - Command Line Interface for YourOrm

require_once __DIR__ . '/vendor/autoload.php';

use YourOrm\Connection;
use YourOrm\Migration\Migrator;
use YourOrm\Migration\SchemaBuilder; // For migrate:make template

// --- Configuration ---
$configPath = __DIR__ . '/config/database.php';
if (!file_exists($configPath)) {
    echo "Configuration file not found. Please copy 'config/database.php.dist' to 'config/database.php' and configure it." . PHP_EOL;
    exit(1);
}
$dbConfig = require $configPath;

// Resolve migrations path relative to project root (where orm-cli.php is)
$projectRoot = __DIR__;
$migrationsPath = $projectRoot . '/' . trim($dbConfig['migrations_path'], '/');
$migrationsNamespace = $dbConfig['migrations_namespace'] ?? 'YourOrm\\Migration\\Database\\';


// --- Argument Parsing ---
if ($argc < 2) {
    display_help();
    exit(1);
}

$command = $argv[1] ?? null;
$commandParts = explode(':', $command ?? '');
$mainCommand = $commandParts[0] ?? null;
$subCommand = $commandParts[1] ?? null;

// --- Database Connection & Migrator ---
try {
    $connection = new Connection(
        $dbConfig['db_host'],
        $dbConfig['db_user'],
        $dbConfig['db_pass'],
        $dbConfig['db_name']
    );
    // Test connection early
    $connection->connect();
} catch (\PDOException $e) {
    echo "Database Connection Error: " . $e->getMessage() . PHP_EOL;
    echo "Please check your settings in 'config/database.php'." . PHP_EOL;
    exit(1);
} catch (\Exception $e) {
    echo "An error occurred: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$migrator = new Migrator($connection, $migrationsPath);
// Adjust Migrator's expected namespace if it differs from its internal default
// This assumes Migrator has a way to set/override its default migration namespace,
// or that its default ($this->migrationNamespace) matches what's in config.
// For now, Migrator.php was hardcoded with 'YourOrm\\Migration\\Database\\'.
// If $dbConfig['migrations_namespace'] is different, Migrator would need a setter or constructor param.
// Let's assume they match as per current Migrator.php.


// --- Command Handling ---
switch ($mainCommand) {
    case 'migrate':
        handle_migrate_command($subCommand, $argv, $migrator, $migrationsPath, $migrationsNamespace);
        break;
    // Future commands like 'seed' could go here
    default:
        echo "Unknown command: {$mainCommand}" . PHP_EOL;
        display_help();
        exit(1);
}

exit(0); // Success

// --- Helper Functions ---

function display_help(): void
{
    echo "YourOrm CLI Help" . PHP_EOL;
    echo "----------------" . PHP_EOL;
    echo "Usage: php orm-cli.php <command> [options]" . PHP_EOL . PHP_EOL;
    echo "Available commands:" . PHP_EOL;
    echo "  migrate:make <MigrationName>    Creates a new migration file (e.g., CreateUsersTable)." . PHP_EOL;
    echo "  migrate:up [--step=N]           Runs pending migrations. Optional N steps." . PHP_EOL;
    echo "  migrate:down [--step=N | --all] Rolls back migrations. N steps, or all." . PHP_EOL;
    echo "  migrate:status                  Shows the status of all migrations." . PHP_EOL;
    echo "  migrate:fresh                   Drops all tables and re-runs all migrations." . PHP_EOL;
    echo "  migrate:refresh [--step=N]      Rolls back and re-runs migrations." . PHP_EOL;
    echo PHP_EOL;
}

function handle_migrate_command(?string $subCommand, array $argv, Migrator $migrator, string $migrationsPath, string $migrationsNamespace): void
{
    global $connection; // For commands that might need direct DB interaction beyond Migrator

    switch ($subCommand) {
        case 'make':
            $migrationName = $argv[2] ?? null;
            if (!$migrationName) {
                echo "Error: Migration name is required for migrate:make." . PHP_EOL;
                echo "Usage: php orm-cli.php migrate:make <MigrationName>" . PHP_EOL;
                exit(1);
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $migrationName)) {
                echo "Error: Migration name '{$migrationName}' is invalid. Use letters, numbers, and underscores, starting with a letter or underscore." . PHP_EOL;
                exit(1);
            }
            make_migration($migrationName, $migrationsPath, $migrationsNamespace);
            break;

        case 'up':
            $options = parse_options(array_slice($argv, 2)); // Get options like --step=N
            $steps = isset($options['step']) ? (int)$options['step'] : 0;

            run_migrations_up($migrator, $steps);
            break;

        case 'down':
            $options = parse_options(array_slice($argv, 2));
            $steps = isset($options['step']) ? (int)$options['step'] : 1; // Default to 1 step (last batch) if not --all
            $all = isset($options['all']);
            run_migrations_down($migrator, $steps, $all);
            break;

        case 'status':
            display_migration_status($migrator);
            break;

        case 'fresh':
            echo "Running migrate:fresh..." . PHP_EOL;
            run_migrations_down($migrator, 0, true); // Rollback all
            echo PHP_EOL . "All migrations rolled back. Now running all migrations up..." . PHP_EOL;
            run_migrations_up($migrator, 0); // Run all up
            echo PHP_EOL . "Migrate:fresh completed." . PHP_EOL;
            break;

        case 'refresh':
            $options = parse_options(array_slice($argv, 2));
            $steps = isset($options['step']) ? (int)$options['step'] : 1; // Default to 1 batch for rollback

            echo "Running migrate:refresh (rolling back {$steps} batch(es) and then running pending migrations)..." . PHP_EOL;
            run_migrations_down($migrator, $steps, false); // Rollback specified steps (not all)
            echo PHP_EOL . "Rollback part completed. Now running all pending migrations up..." . PHP_EOL;
            run_migrations_up($migrator, 0); // Run all pending migrations
            echo PHP_EOL . "Migrate:refresh completed." . PHP_EOL;
            break;

        default:
            echo "Unknown migrate command: {$subCommand}" . PHP_EOL;
            display_help();
            exit(1);
    }
}

function make_migration(string $migrationName, string $migrationsPath, string $migrationNamespace): void
{
    // Ensure migrations directory exists
    if (!is_dir($migrationsPath)) {
        if (!mkdir($migrationsPath, 0755, true)) {
            echo "Error: Could not create migrations directory: {$migrationsPath}" . PHP_EOL;
            exit(1);
        }
        echo "Created migrations directory: {$migrationsPath}" . PHP_EOL;
    }

    $timestamp = date('YmdHis');
    // ClassName_YYYYMMDDHHMMSS
    $className = $migrationName . '_' . $timestamp;
    // FileName YYYYMMDDHHMMSS_ClassName.php
    $fileName = $timestamp . '_' . $migrationName . '.php';
    $filePath = $migrationsPath . '/' . $fileName;

    // Ensure the namespace is correctly formatted (ends with a backslash)
    $namespace = rtrim($migrationNamespace, '\\') . '\\';

    $stub = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use YourOrm\Migration\AbstractMigration;
use YourOrm\Migration\SchemaBuilder;
use YourOrm\Connection; // For postUp type hinting

class {$className} extends AbstractMigration
{
    public function up(SchemaBuilder \$schema): void
    {
        // --- Example: Create a new table ---
        // \$schema->createTable('{$migrationName_lowercase}', function(SchemaBuilder \$table) {
        //     \$table->id();
        //     \$table->string('name', 191)->unique(); // VARCHAR(191) for UTF8MB4 compatibility
        //     \$table->text('description')->nullable();
        //     \$table->integer('count')->default(0);
        //     \$table->decimal('price', 10, 2)->unsigned()->nullable(); // Example DECIMAL(10,2)
        //     \$table->boolean('is_active')->default(true);
        //     \$table->date('published_on')->nullable();
        //     \$table->datetime('processed_at')->nullable();
        //     \$table->timestamps(); // created_at and updated_at
        // });

        // --- Example: Modify an existing table (add a column) ---
        // \$schema->changeColumn('existing_table_name', 'column_to_add_if_not_exists_or_change', [
        //     'name' => 'new_status_column',      // Use current name if not renaming
        //     'type' => 'string',               // e.g. 'string', 'integer', 'text', 'boolean', 'date', 'datetime', 'float', 'decimal'
        //     'length' => 50,                   // Optional, for 'string' type
        //     // 'precision' => 10, 'scale' => 2, // Optional, for 'decimal' or 'float'
        //     'nullable' => false,
        //     'default' => 'pending',
        //     // 'unsigned' => true,             // For numeric types
        //     // 'unique' => true,
        //     // 'after' => 'some_other_column' // MySQL specific for column order - not directly supported by SchemaBuilder generic options
        // ]);

        // --- Example: Add a foreign key constraint ---
        // \$schema->addForeignKeyConstraint(
        //     'source_table_name',          // Table that will have the foreign key
        //     'source_column_name_fk',      // Column(s) in source_table_name
        //     'target_table_name',          // Table the foreign key references
        //     'target_column_name_pk',      // Column(s) in target_table_name (usually primary key)
        //     'fk_custom_constraint_name',  // Optional: Name for the constraint
        //     'CASCADE',                    // Optional: ON DELETE action (e.g., 'RESTRICT', 'CASCADE', 'SET NULL')
        //     'CASCADE'                     // Optional: ON UPDATE action
        // );
    }

    public function down(SchemaBuilder \$schema): void
    {
        // --- Example: Drop a table ---
        // \$schema->dropTableIfExists('{$migrationName_lowercase}');

        // --- Example: Drop a column (SchemaBuilder needs a dropColumn method) ---
        // \$schema->dropColumn('existing_table_name', 'new_status_column');
        // Note: dropColumn is not yet implemented in the provided SchemaBuilder.
        // You would typically use:
        // \$schema->executeStatements("ALTER TABLE `existing_table_name` DROP COLUMN `new_status_column`;");


        // --- Example: Drop a foreign key constraint ---
        // Make sure to use the correct constraint name, especially if it was auto-generated.
        // \$schema->dropForeignKeyConstraint('source_table_name', 'fk_custom_constraint_name_or_auto_generated_one');
    }

    /**
     * Optional: Actions to perform after 'up' migration (e.g., data seeding).
     * public function postUp(Connection \$connection): void
     * {
     *     // Example: Seed initial data using QueryBuilder
     *     // \$qb = new \YourOrm\QueryBuilder(\$connection);
     *     // \$qb->insert('{$migrationName_lowercase}', [
     *     //    ['name' => 'Default Item 1', 'description' => 'First item created via postUp'],
     *     //    ['name' => 'Default Item 2', 'description' => 'Second item via postUp']
     *     // ])->execute(); // Assuming insert can take array of arrays for batch insert or loop it
     *
     *     // Or direct PDO execution:
     *     // \$stmt = \$connection->getPdo()->prepare("INSERT INTO `{$migrationName_lowercase}` (name) VALUES (?)");
     *     // \$stmt->execute(['My Seeded Item']);
     * }
     */
}

PHP;
    // Add lowercase table name example
    $stub = str_replace('{$migrationName_lowercase}', strtolower($migrationName), $stub);

    if (file_put_contents($filePath, $stub)) {
        echo "Migration created successfully: {$filePath}" . PHP_EOL;
    } else {
        echo "Error: Could not create migration file: {$filePath}" . PHP_EOL;
        exit(1);
    }
}

function run_migrations_up(Migrator $migrator, int $steps = 0): void
{
    $pendingMigrations = $migrator->getPendingMigrations();

    if (empty($pendingMigrations)) {
        echo "No pending migrations to run." . PHP_EOL;
        return;
    }

    echo "Found " . count($pendingMigrations) . " pending migration(s)." . PHP_EOL;

    $migrationsToRun = ($steps > 0) ? array_slice($pendingMigrations, 0, $steps) : $pendingMigrations;

    $runCount = 0;
    foreach ($migrationsToRun as $migrationName) {
        echo "Migrating: {$migrationName}" . PHP_EOL;
        if ($migrator->runUp($migrationName)) {
            echo "Migrated:  {$migrationName}" . PHP_EOL;
            $runCount++;
        } else {
            echo "Failed to migrate: {$migrationName}. Halting further migrations." . PHP_EOL;
            // Consider if you want to halt or continue on error. Halting is safer.
            break;
        }
    }
    echo PHP_EOL . "Migrations completed. {$runCount} migration(s) executed." . PHP_EOL;
}

function run_migrations_down(Migrator $migrator, int $steps, bool $all): void
{
    if ($all) {
        echo "Rolling back all executed migrations..." . PHP_EOL;
        $executedMigrations = $migrator->getExecutedMigrations(); // These are sorted ASC by default
        $migrationsToRollback = array_reverse($executedMigrations); // Rollback from newest to oldest
        if (empty($migrationsToRollback)) {
            echo "No migrations to roll back." . PHP_EOL;
            return;
        }
    } else {
        echo "Rolling back last {$steps} batch(es)..." . PHP_EOL;
        // This logic might need refinement in Migrator.
        // For now, getLastBatchMigrations() gets the very last batch.
        // Repeating this N times for N steps is one way if getLastBatchMigrations always gets the current last one.
        $migrationsToRollback = [];
        $batchesToRollback = $steps;

        // Get distinct batch numbers, sorted descending
        $distinctBatches = $migrator->getDistinctBatchesDesc($batchesToRollback);

        if (empty($distinctBatches)) {
            echo "No executed migration batches found to roll back." . PHP_EOL;
            return;
        }

        foreach($distinctBatches as $batchNumber) {
            $migrationsInBatch = $migrator->getMigrationsByBatchNumber($batchNumber);
            $migrationsToRollback = array_merge($migrationsToRollback, $migrationsInBatch);
        }

        if (empty($migrationsToRollback)) {
            echo "No migrations found for the specified number of batches to roll back." . PHP_EOL;
            return;
        }
    }

    echo "Found " . count($migrationsToRollback) . " migration(s) to roll back." . PHP_EOL;
    $rolledBackCount = 0;
    foreach ($migrationsToRollback as $migrationName) {
        echo "Rolling back: {$migrationName}" . PHP_EOL;
        if ($migrator->runDown($migrationName)) {
            echo "Rolled back:  {$migrationName}" . PHP_EOL;
            $rolledBackCount++;
        } else {
            echo "Failed to roll back: {$migrationName}. Halting further rollbacks." . PHP_EOL;
            break;
        }
    }
    echo PHP_EOL . "Rollback completed. {$rolledBackCount} migration(s) rolled back." . PHP_EOL;
}

function display_migration_status(Migrator $migrator): void
{
    $allFiles = $migrator->getAllMigrationFiles();
    $executedMigrationsDetails = $migrator->getExecutedMigrations(); // Now returns details

    if (empty($allFiles) && empty($executedMigrationsDetails)) {
        echo "No migrations found (neither files nor records in database)." . PHP_EOL;
        return;
    }

    echo "+----------+--------------------------------------+---------------------+---------+" . PHP_EOL;
    echo "| Status   | Migration Name                       | Executed At         | Batch   |" . PHP_EOL;
    echo "+----------+--------------------------------------+---------------------+---------+" . PHP_EOL;

    // Combine all known migration names (from files and DB) to ensure all are listed
    $allKnownMigrationNames = array_unique(array_merge($allFiles, array_keys($executedMigrationsDetails)));
    sort($allKnownMigrationNames);


    foreach ($allKnownMigrationNames as $migrationName) {
        $status = '';
        $executedAt = 'N/A';
        $batch = 'N/A';

        if (isset($executedMigrationsDetails[$migrationName])) {
            $status = 'Up';
            $details = $executedMigrationsDetails[$migrationName];
            $executedAt = $details['executed_at'] ?? 'N/A';
            $batch = (string)($details['batch'] ?? 'N/A');
        } elseif (in_array($migrationName, $allFiles)) {
            $status = 'Pending'; // File exists but not in DB executed list
        } else {
            // This case should ideally not happen if $executedMigrationsDetails keys are a subset of $allFiles or vice-versa
            // But could mean a DB record exists for a file that was deleted.
            $status = 'Missing?';
        }

        printf("| %-8s | %-36s | %-19s | %-7s |\n", $status, $migrationName, $executedAt, $batch);
    }

    echo "+----------+--------------------------------------+---------------------+---------+" . PHP_EOL;
}


function parse_options(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (strpos($arg, '--') === 0) {
            $parts = explode('=', ltrim($arg, '-'), 2);
            $key = $parts[0];
            $value = $parts[1] ?? true; // If no value, it's a boolean flag
            $options[$key] = $value;
        }
    }
    return $options;
}

?>
