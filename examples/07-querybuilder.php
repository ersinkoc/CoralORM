<?php

declare(strict_types=1);

namespace Examples;

require_once __DIR__ . '/bootstrap.php';
// No need to load 02-define-entity.php if we are not using specific Entity classes here,
// but QueryBuilder can be used to fetch data that could be hydrated into entities.

use App\QueryBuilder; // Assuming QueryBuilder is in App namespace

echo PHP_EOL . "--- Example 07: Using QueryBuilder Directly ---" . PHP_EOL;

$connection = get_db_connection();

if (!$connection) {
    echo "Failed to get database connection." . PHP_EOL;
    exit(1);
}

try {
    // Create a QueryBuilder instance
    $qb = new QueryBuilder($connection);

    // --- 1. Build and execute a SELECT query ---
    echo PHP_EOL . "1. Selecting users with 'active' status, ordered by username, limited to 5..." . PHP_EOL;

    $qb->select('id', 'username', 'email', 'status')
        ->from('users')
        ->where('status', '=', 'active')
        ->orderBy('username', 'ASC')
        ->limit(5);

    $sql = $qb->getSql();
    $params = $qb->getParameters();

    echo "Generated SQL: " . $sql . PHP_EOL;
    echo "Parameters: " . print_r($params, true) . PHP_EOL;

    // Execute the query (fetchAll also resets the QueryBuilder instance)
    $activeUsers = $qb->fetchAll(); // QueryBuilder::fetchAll() was already called by $qb above.
                                    // Oh, wait, QueryBuilder's fetchAll resets *after* execution.
                                    // So, we need to re-build for a new fetchAll call or use the result from the first.
                                    // Let's re-build for clarity.

    $qb = new QueryBuilder($connection); // Get a fresh QB
    $activeUsersResults = $qb->select('id', 'username', 'email', 'status')
                             ->from('users')
                             ->where('status', '=', 'active')
                             ->orderBy('username', 'ASC')
                             ->limit(5)
                             ->fetchAll();


    if (!empty($activeUsersResults)) {
        echo "Found " . count($activeUsersResults) . " active user(s):" . PHP_EOL;
        foreach ($activeUsersResults as $userRow) {
            // $userRow is an associative array
            echo "  - ID: {$userRow['id']}, Username: {$userRow['username']}, Email: {$userRow['email']}, Status: {$userRow['status']}" . PHP_EOL;
        }
    } else {
        echo "No active users found with QueryBuilder." . PHP_EOL;
    }

    // --- 2. Build and execute an INSERT query ---
    // Note: QueryBuilder's insert() method directly executes the query.
    echo PHP_EOL . "2. Inserting a new user using QueryBuilder..." . PHP_EOL;
    $newUserData = [
        'username' => 'qb_user_' . time(),
        'email' => 'qb.user.' . time() . '@example.com',
        'status' => 'pending'
    ];

    // QueryBuilder needs a fresh instance or reset before building a different query type
    $qb = new QueryBuilder($connection); // Fresh instance
    if ($qb->insert('users', $newUserData)) {
        echo "User '{$newUserData['username']}' inserted successfully using QueryBuilder." . PHP_EOL;
        // To get the ID, you'd typically need a $connection->lastInsertId() method.
    } else {
        echo "Failed to insert user using QueryBuilder." . PHP_EOL;
    }

    // --- 3. Build and execute an UPDATE query ---
    // Note: QueryBuilder's execute() is used for UPDATE and DELETE after building the query.
    echo PHP_EOL . "3. Updating user '{$newUserData['username']}' status to 'active_qb' using QueryBuilder..." . PHP_EOL;
    $qb = new QueryBuilder($connection); // Fresh instance
    $updateData = ['status' => 'active_qb'];

    // Define the where clause for the update
    $qb->update('users', $updateData)
        ->where('username', '=', $newUserData['username']);

    echo "Generated SQL for UPDATE: " . $qb->getSql() . PHP_EOL;
    echo "Parameters for UPDATE: " . print_r($qb->getParameters(), true) . PHP_EOL;

    if ($qb->execute()) { // Executes the UPDATE query
        echo "User '{$newUserData['username']}' updated successfully using QueryBuilder." . PHP_EOL;
    } else {
        echo "Failed to update user '{$newUserData['username']}' using QueryBuilder." . PHP_EOL;
    }

    // --- 4. Build and execute a DELETE query ---
    echo PHP_EOL . "4. Deleting user '{$newUserData['username']}' using QueryBuilder..." . PHP_EOL;
    $qb = new QueryBuilder($connection); // Fresh instance
    $qb->delete('users')
        ->where('username', '=', $newUserData['username']);

    echo "Generated SQL for DELETE: " . $qb->getSql() . PHP_EOL;
    echo "Parameters for DELETE: " . print_r($qb->getParameters(), true) . PHP_EOL;

    if ($qb->execute()) { // Executes the DELETE query
        echo "User '{$newUserData['username']}' deleted successfully using QueryBuilder." . PHP_EOL;
    } else {
        echo "Failed to delete user '{$newUserData['username']}' using QueryBuilder." . PHP_EOL;
    }


} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
} catch (\LogicException $e) { // QueryBuilder might throw LogicException (e.g. UPDATE/DELETE without WHERE)
    echo "A LogicException occurred with QueryBuilder: " . $e->getMessage() . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "--- End of Example 07 ---" . PHP_EOL;
?>
