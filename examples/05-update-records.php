<?php

declare(strict_types=1);

namespace Examples;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/02-define-entity.php'; // To ensure User entity class is loaded

use CoralORM\Repository;
use Examples\Entity\User;

echo PHP_EOL . "--- Example 05: Updating Records ---" . PHP_EOL;

$connection = get_db_connection();

if (!$connection) {
    echo "Failed to get database connection." . PHP_EOL;
    exit(1);
}

try {
    $userRepository = new Repository($connection, User::class);

    // --- Update a User ---
    // First, we need to find a user to update.
    // Let's try to find the user 'johndoe_example' created in 03-create-records.php
    // Or, find any user if that one doesn't exist.
    $usersToUpdate = $userRepository->findBy(['username' => 'johndoe_example'], null, 1);

    if (empty($usersToUpdate)) {
        echo "User 'johndoe_example' not found. Fetching any user to update instead..." . PHP_EOL;
        $allUsers = $userRepository->findAll();
        if (!empty($allUsers)) {
            $userToUpdate = $allUsers[0]; // Get the first user
        } else {
            $userToUpdate = null;
        }
    } else {
        $userToUpdate = $usersToUpdate[0];
    }

    if ($userToUpdate instanceof User) {
        echo "Found user to update: ID {$userToUpdate->id}, Username: {$userToUpdate->username}, Status: {$userToUpdate->status}" . PHP_EOL;

        // Modify properties
        $originalStatus = $userToUpdate->status;
        $newStatus = ($originalStatus === 'active') ? 'inactive_updated' : 'active_updated';
        $originalEmail = $userToUpdate->email;
        $newEmailDomain = 'updated.example.com';
        $emailParts = explode('@', $originalEmail ?? 'user@example.com');
        $newEmail = $emailParts[0] . '@' . $newEmailDomain;


        echo "Updating status to '{$newStatus}' and email to '{$newEmail}'..." . PHP_EOL;
        $userToUpdate->status = $newStatus;
        $userToUpdate->email = $newEmail; // Make sure email is unique if DB enforces it

        // Save the updated user
        if ($userRepository->save($userToUpdate)) {
            echo "User ID {$userToUpdate->id} updated successfully." . PHP_EOL;

            // Optionally, verify by fetching the user again
            $updatedUser = $userRepository->find($userToUpdate->id);
            if ($updatedUser instanceof User) {
                echo "Verified update: Username: {$updatedUser->username}, New Status: {$updatedUser->status}, New Email: {$updatedUser->email}" . PHP_EOL;
            } else {
                echo "Could not re-fetch user to verify update." . PHP_EOL;
            }
        } else {
            echo "Failed to update user ID {$userToUpdate->id}." . PHP_EOL;
        }
    } else {
        echo "No user found to demonstrate update. Please run 03-create-records.php first." . PHP_EOL;
    }

} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
    echo "This could be due to unique constraint violations (e.g., email already exists if you didn't change it enough) or other database issues." . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "--- End of Example 05 ---" . PHP_EOL;
?>
