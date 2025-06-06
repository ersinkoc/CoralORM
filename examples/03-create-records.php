<?php

declare(strict_types=1);

namespace Examples;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/02-define-entity.php'; // To ensure User and Post entity classes are loaded

use YourOrm\Repository;
use Examples\Entity\User; // User class defined in 02-define-entity.php
use Examples\Entity\Post;  // Post class defined in 02-define-entity.php

echo PHP_EOL . "--- Example 03: Creating Records ---" . PHP_EOL;

$connection = get_db_connection();

if (!$connection) {
    echo "Failed to get database connection. Please check config.php and bootstrap.php." . PHP_EOL;
    exit(1);
}

try {
    // Create a Repository for the User entity
    // The Repository needs the Connection instance and the fully qualified class name of the entity
    $userRepository = new Repository($connection, User::class);

    // --- Create a new User ---
    echo "Attempting to create a new user..." . PHP_EOL;
    $newUser = new User();
    $newUser->username = 'johndoe_example'; // Set properties using magic __set
    $newUser->email = 'john.doe.' . time() . '@example.com'; // Unique email
    $newUser->status = 'active';

    // Save the new user
    if ($userRepository->save($newUser)) {
        echo "User '{$newUser->username}' created successfully." . PHP_EOL;
        if ($newUser->id !== null) {
            echo "New user ID (from entity after save, if Repository/save updates it): " . $newUser->id . PHP_EOL;
            // Note: Current Repository::save does not set back the lastInsertId to the entity.
            // This would be a potential enhancement for the ORM.
            // To get the ID, you might need to fetch the user again or Connection needs a lastInsertId() method.
        }

        // --- Create a Post for this User ---
        // First, we need the ID of the user. Since save() doesn't set it back,
        // let's fetch the user we just created to get their ID.
        // This is a bit inefficient and highlights an area for ORM improvement.

        // For simplicity, let's assume we know the ID or can query for it.
        // A more robust way: query by a unique field like email if ID is not returned.
        $createdUser = $userRepository->findBy(['email' => $newUser->email], null, 1);
        if (!empty($createdUser) && $createdUser[0] instanceof User) {
            $actualNewUser = $createdUser[0];
            echo "Fetched the newly created user to get ID: " . $actualNewUser->id . PHP_EOL;

            $postRepository = new Repository($connection, Post::class);
            $newPost = new Post();
            $newPost->user_id = (int)$actualNewUser->id; // Ensure it's an int
            $newPost->title = "My First Blog Post by {$actualNewUser->username}";
            $newPost->content = "Hello world! This is an example post.";
            $newPost->published_at = date('Y-m-d H:i:s'); // Or null if not published

            if ($postRepository->save($newPost)) {
                echo "Post '{$newPost->title}' created successfully for user ID {$actualNewUser->id}." . PHP_EOL;
            } else {
                echo "Failed to create post for user ID {$actualNewUser->id}." . PHP_EOL;
            }
        } else {
            echo "Could not retrieve the newly created user to make a post." . PHP_EOL;
        }


    } else {
        echo "Failed to create user '{$newUser->username}'." . PHP_EOL;
        // You could log error details from the ORM if it provided them
    }

    // --- Create another user for batch operations later ---
    echo PHP_EOL . "Attempting to create a second user..." . PHP_EOL;
    $anotherUser = new User();
    $anotherUser->username = 'jane_doe_example';
    $anotherUser->email = 'jane.doe.' . time() . '@example.com';
    $anotherUser->status = 'inactive';

    if ($userRepository->save($anotherUser)) {
        echo "User '{$anotherUser->username}' created successfully." . PHP_EOL;
    } else {
        echo "Failed to create user '{$anotherUser->username}'." . PHP_EOL;
    }


} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
    echo "This might be due to database schema issues (table 'users' or 'posts' not existing or incorrect columns - see schema.sql) or connection problems." . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "--- End of Example 03 ---" . PHP_EOL;
?>
