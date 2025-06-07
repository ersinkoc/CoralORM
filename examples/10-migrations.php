<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

namespace Examples\MigrationsDemo;

use CoralORM\Connection;
use CoralORM\Migration\Migrator;

echo PHP_EOL . "--- Example 10: Running Migrations with CoralORM ---" . PHP_EOL;

$connection = get_db_connection();

if (!$connection) {
    echo "Failed to get database connection. Please check config.php and bootstrap.php." . PHP_EOL;
    exit(1);
}

// The Migrator expects migration files to be in a specific path and namespace.
// As per CoralORM\Migration\Migrator, the namespace is 'CoralORM\Migration\Database\'
// And the path is passed to its constructor.
// We created '20240315000000_CreateExampleItemsTable.php' in 'src/Migration/Database/'
$migrationsPath = __DIR__ . '/../src/Migration/Database'; // Relative path to the migration files

try {
    $migrator = new Migrator($connection, $migrationsPath);

    // Name of our example migration (filename without .php)
    $exampleMigrationName = '20240315000000_CreateExampleItemsTable';

    echo "\n--- Checking Migration Status ---\n";
    $executedMigrations = $migrator->getExecutedMigrations();
    $pendingMigrations = $migrator->getPendingMigrations();

    echo "Executed Migrations:\n";
    if (empty($executedMigrations)) {
        echo "  No migrations executed yet.\n";
    } else {
        foreach ($executedMigrations as $name => $details) {
            echo "  - {$name} (Batch: {$details['batch']}, Executed At: {$details['executed_at']})\n";
        }
    }

    echo "Pending Migrations:\n";
    if (empty($pendingMigrations)) {
        echo "  No pending migrations.\n";
    } else {
        foreach ($pendingMigrations as $name) {
            echo "  - {$name}\n";
        }
    }

    // --- Running UP migration ---
    if (in_array($exampleMigrationName, $pendingMigrations)) {
        echo "\n--- Attempting to run UP for: {$exampleMigrationName} ---\n";
        if ($migrator->runUp($exampleMigrationName)) {
            echo "Successfully ran UP for {$exampleMigrationName}.\n";
        } else {
            echo "Failed to run UP for {$exampleMigrationName}.\n";
        }
    } else {
        echo "\n--- Migration {$exampleMigrationName} is not pending or already executed. Skipping UP run. ---\n";
        // To re-run for testing, you might need to run DOWN first or manually clear from migrations table.
    }

    // Verify status again
    echo "\n--- Migration Status After UP attempt ---\n";
    $executedMigrationsAfterUp = $migrator->getExecutedMigrations();
    echo "Executed Migrations:\n";
    if (empty($executedMigrationsAfterUp)) {
        echo "  No migrations executed yet.\n";
    } else {
        foreach ($executedMigrationsAfterUp as $name => $details) {
            echo "  - {$name} (Batch: {$details['batch']}, Executed At: {$details['executed_at']})\n";
        }
    }


    // --- Running DOWN migration ---
    // Check if it was executed before trying to run down
    if (array_key_exists($exampleMigrationName, $executedMigrationsAfterUp)) {
        echo "\n--- Attempting to run DOWN for: {$exampleMigrationName} ---\n";
        if ($migrator->runDown($exampleMigrationName)) {
            echo "Successfully ran DOWN for {$exampleMigrationName}.\n";
        } else {
            echo "Failed to run DOWN for {$exampleMigrationName}.\n";
        }
    } else {
        echo "\n--- Migration {$exampleMigrationName} was not executed. Skipping DOWN run. ---\n";
    }

    // Verify status final
    echo "\n--- Final Migration Status ---\n";
    $finalExecutedMigrations = $migrator->getExecutedMigrations();
    echo "Executed Migrations:\n";
    if (empty($finalExecutedMigrations)) {
        echo "  No migrations executed.\n";
    } else {
        foreach ($finalExecutedMigrations as $name => $details) {
            echo "  - {$name} (Batch: {$details['batch']}, Executed At: {$details['executed_at']})\n";
        }
    }


} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
    echo "Trace: " . $e->getTraceAsString() . PHP_EOL;
}

echo PHP_EOL . "--- End of Example 10 ---" . PHP_EOL;

?>
