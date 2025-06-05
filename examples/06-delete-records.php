<?php

declare(strict_types=1);

namespace Examples;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/02-define-entity.php'; // To ensure User entity class is loaded

use App\Repository;
use Examples\Entity\User;

echo PHP_EOL . "--- Example 06: Deleting Records ---" . PHP_EOL;

$connection = get_db_connection();

if (!$connection) {
    echo "Failed to get database connection." . PHP_EOL;
    exit(1);
}

try {
    $userRepository = new Repository($connection, User::class);

    // --- Delete a User ---
    // First, we need to find a user to delete.
    // Let's try to find the user 'jane_doe_example' created in 03-create-records.php
    // Or, find any user if that one doesn't exist.
    $usersToDelete = $userRepository->findBy(['username' => 'jane_doe_example'], null, 1);

    if (empty($usersToDelete)) {
        echo "User 'jane_doe_example' not found. Fetching any user to delete instead..." . PHP_EOL;
        // Be careful with deleting random users in a real scenario!
        // For this example, let's try to find a user with 'updated' in their status or email
        $candidates = $userRepository->findBy(['status' => 'inactive_updated']);
        if(empty($candidates)) {
            $candidates = $userRepository->findBy(['status' => 'active_updated']);
        }
         if(empty($candidates)) {
            // Fallback: find any user, but this is risky
            $allUsers = $userRepository->findAll();
            if (!empty($allUsers)) {
                 // Avoid deleting the very first user if possible, to keep data for other examples
                $userToDelete = (count($allUsers) > 1) ? $allUsers[count($allUsers)-1] : $allUsers[0];
            } else {
                $userToDelete = null;
            }
        } else {
            $userToDelete = $candidates[0];
        }
    } else {
        $userToDelete = $usersToDelete[0];
    }

    if ($userToDelete instanceof User) {
        echo "Found user to delete: ID {$userToDelete->id}, Username: {$userToDelete->username}" . PHP_EOL;

        // Delete the user
        if ($userRepository->delete($userToDelete)) {
            echo "User ID {$userToDelete->id} ('{$userToDelete->username}') deleted successfully." . PHP_EOL;

            // Optionally, verify by trying to find the user again
            $deletedUser = $userRepository->find($userToDelete->id);
            if ($deletedUser === null) {
                echo "Verified: User ID {$userToDelete->id} no longer found in the database." . PHP_EOL;
            } else {
                echo "Verification issue: User ID {$userToDelete->id} was still found after attempting delete." . PHP_EOL;
            }
        } else {
            echo "Failed to delete user ID {$userToDelete->id}." . PHP_EOL;
        }
    } else {
        echo "No user found to demonstrate delete. Please run 03-create-records.php first." . PHP_EOL;
    }

} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
    echo "This could be due to foreign key constraints if the user has related records (e.g., posts) and the database schema doesn't specify ON DELETE CASCADE." . PHP_EOL;
    echo "Our schema.sql for 'posts' table *does* include ON DELETE CASCADE for user_id." . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "--- End of Example 06 ---" . PHP_EOL;
?>
