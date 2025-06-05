<?php

declare(strict_types=1);

namespace Examples;

require_once __DIR__ . '/bootstrap.php';

use App\Connection; // Assuming Connection is in App namespace

echo PHP_EOL . "--- Example 01: Connecting to the Database ---" . PHP_EOL;

// The get_db_connection() function is defined in bootstrap.php
$connection = get_db_connection();

if ($connection instanceof Connection) {
    echo "Connection object created successfully." . PHP_EOL;
    try {
        // Attempt to establish the actual PDO connection
        $pdo = $connection->connect();
        echo "Successfully connected to the database server: " . $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) . PHP_EOL;

        // You can try a simple query
        $stmt = $connection->execute("SELECT 1");
        if ($stmt->fetchColumn()) {
            echo "Successfully executed a simple query (SELECT 1)." . PHP_EOL;
        } else {
            echo "Failed to execute a simple query." . PHP_EOL;
        }

        // Disconnect (optional, PDO does this on script end)
        $connection->disconnect();
        echo "Disconnected from the database." . PHP_EOL;

    } catch (\PDOException $e) {
        echo "PDO Connection Error: " . $e->getMessage() . PHP_EOL;
        echo "Please ensure your database server is running and credentials in 'examples/config.php' are correct." . PHP_EOL;
    } catch (\Exception $e) {
        echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
    }
} else {
    echo "Failed to create a Connection object. Check bootstrap.php and config.php." . PHP_EOL;
}

echo PHP_EOL . "--- End of Example 01 ---" . PHP_EOL;

?>
