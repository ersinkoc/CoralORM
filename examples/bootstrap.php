<?php

declare(strict_types=1);

// Include Composer's autoloader
$autoloaderPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloaderPath)) {
    die("Composer autoloader not found. Please run 'composer install' in the root directory." . PHP_EOL);
}
require_once $autoloaderPath;

use CoralORM\Connection;

// --- Database Configuration ---
$configPath = __DIR__ . '/config.php';
$dbConfig = null;

if (file_exists($configPath)) {
    $dbConfig = require $configPath;
} else {
    echo "Database configuration file (config.php) not found in 'examples/' directory." . PHP_EOL;
    echo "Please copy 'examples/config.php.dist' to 'examples/config.php' and update your database credentials." . PHP_EOL;
    // Optionally, you could exit here or use default/fallback credentials if appropriate for examples.
    // For this example, we'll allow scripts to proceed and they can handle $dbConfig being null if they need a connection.
}

// --- Helper function to get a Connection instance ---
function get_db_connection(): ?Connection
{
    global $dbConfig; // Use the $dbConfig from the global scope of this bootstrap file

    if ($dbConfig === null) {
        echo "Database configuration is missing. Cannot create a connection." . PHP_EOL;
        return null;
    }

    try {
        $connection = new Connection(
            $dbConfig['db_host'],
            $dbConfig['db_user'],
            $dbConfig['db_pass'],
            $dbConfig['db_name']
        );
        // It's good practice to actually connect here to catch immediate issues,
        // but the Connection class establishes the PDO connection on the first query or ->connect().
        // For simplicity in examples, we'll let individual example files call ->connect()
        // or implicitly connect via ->execute().
        return $connection;
    } catch (\PDOException $e) {
        echo "Database Connection Error in bootstrap: " . $e->getMessage() . PHP_EOL;
        return null;
    } catch (\Exception $e) {
        echo "An unexpected error occurred in bootstrap while creating Connection: " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

echo "Bootstrap loaded. Autoloader included." . PHP_EOL;
if ($dbConfig) {
    echo "Database configuration loaded from config.php." . PHP_EOL;
}

// You could also define a global $connection variable here if preferred for simpler examples,
// but returning it from a function is often cleaner.
// global $connectionInstance;
// $connectionInstance = get_db_connection();

?>
