<?php

declare(strict_types=1);

namespace Examples;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/02-define-entity.php'; // To ensure User and Post entity classes are loaded

use CoralORM\Repository;
use Examples\Entity\User;
use Examples\Entity\Post;

echo PHP_EOL . "--- Example 04: Reading Records ---" . PHP_EOL;

$connection = get_db_connection();

if (!$connection) {
    echo "Failed to get database connection." . PHP_EOL;
    exit(1);
}

try {
    $userRepository = new Repository($connection, User::class);
    $postRepository = new Repository($connection, Post::class);

    // --- 1. Find a user by ID ---
    // Assuming a user with ID 1 exists (created in 03-create-records.php or manually via schema.sql)
    // You might need to adjust the ID based on your database state.
    echo PHP_EOL . "1. Attempting to find a user by ID (e.g., ID 1)..." . PHP_EOL;
    $userIdToFind = 1; // Change this if user ID 1 doesn't exist
    $user = $userRepository->find($userIdToFind);

    if ($user instanceof User) {
        echo "Found User with ID {$userIdToFind}:" . PHP_EOL;
        echo "  ID: " . $user->id . PHP_EOL;
        echo "  Username: " . $user->username . PHP_EOL;
        echo "  Email: " . $user->email . PHP_EOL;
        echo "  Status: " . $user->status . PHP_EOL;
        echo "  Created At: " . $user->created_at . PHP_EOL;
    } else {
        echo "User with ID {$userIdToFind} not found." . PHP_EOL;
        echo "Please run 03-create-records.php or ensure a user with this ID exists." . PHP_EOL;
    }

    // --- 2. Find all users ---
    echo PHP_EOL . "2. Attempting to find all users..." . PHP_EOL;
    $allUsers = $userRepository->findAll();

    if (!empty($allUsers)) {
        echo "Found " . count($allUsers) . " user(s):" . PHP_EOL;
        foreach ($allUsers as $u) {
            if ($u instanceof User) {
                echo "  - User ID: {$u->id}, Username: {$u->username}, Email: {$u->email}, Status: {$u->status}" . PHP_EOL;
            }
        }
    } else {
        echo "No users found in the database." . PHP_EOL;
    }

    // --- 3. Find users by criteria ---
    echo PHP_EOL . "3. Attempting to find 'active' users, ordered by username..." . PHP_EOL;
    $activeUsers = $userRepository->findBy(
        ['status' => 'active'],      // Criteria: status is 'active'
        ['username' => 'ASC']       // Order by: username ascending
    );

    if (!empty($activeUsers)) {
        echo "Found " . count($activeUsers) . " 'active' user(s):" . PHP_EOL;
        foreach ($activeUsers as $activeUser) {
            if ($activeUser instanceof User) {
                echo "  - User ID: {$activeUser->id}, Username: {$activeUser->username}, Status: {$activeUser->status}" . PHP_EOL;
            }
        }
    } else {
        echo "No 'active' users found." . PHP_EOL;
    }

    // --- 4. Find posts by a specific user ---
    // First, let's find a user (e.g., the first one found overall, or user with ID 1 if they exist)
    $targetUserForPosts = $user ?? ($allUsers[0] ?? null); // Use previously found user or first from findAll

    if ($targetUserForPosts instanceof User && $targetUserForPosts->id !== null) {
        echo PHP_EOL . "4. Attempting to find posts by user '{$targetUserForPosts->username}' (ID: {$targetUserForPosts->id})..." . PHP_EOL;
        $userPosts = $postRepository->findBy(
            ['user_id' => $targetUserForPosts->id],
            ['created_at' => 'DESC']
        );

        if (!empty($userPosts)) {
            echo "Found " . count($userPosts) . " post(s) for user '{$targetUserForPosts->username}':" . PHP_EOL;
            foreach ($userPosts as $post) {
                if ($post instanceof Post) {
                    echo "  - Post ID: {$post->id}, Title: \"{$post->title}\", Published: {$post->published_at}" . PHP_EOL;
                }
            }
        } else {
            echo "No posts found for user '{$targetUserForPosts->username}'." . PHP_EOL;
        }
    } else {
        echo PHP_EOL . "Skipping finding posts by user as no target user was identified from previous steps." . PHP_EOL;
    }


} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "--- End of Example 04 ---" . PHP_EOL;
?>
