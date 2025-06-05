<?php

declare(strict_types=1);

namespace Examples\Entity; // Using a sub-namespace for entities

use App\Entity;

echo PHP_EOL . "--- Example 02: Defining an Entity ---" . PHP_EOL;

/**
 * Class User
 * Represents a user in our application.
 *
 * @property int|null $id The user's ID. Null if the user is new.
 * @property string|null $username The user's chosen username.
 * @property string|null $email The user's email address.
 * @property string|null $status The user's status (e.g., 'active', 'inactive').
 * @property string|null $created_at Timestamp of when the user was created.
 */
class User extends Entity
{
    // Define properties for type hinting and auto-completion if desired,
    // though Entity class uses magic methods __get and __set.
    // These are more for documentation and static analysis.
    public ?int $id = null;
    public ?string $username = null;
    public ?string $email = null;
    public ?string $status = null;
    public ?string $created_at = null; // Assuming it's fetched as a string

    /**
     * Gets the database table name for this entity.
     *
     * @return string The table name.
     */
    public function getTableName(): string
    {
        return 'users'; // Corresponds to the table name in schema.sql
    }

    /**
     * Gets the primary key column name for this entity.
     *
     * @return string The primary key column name.
     */
    public function getPrimaryKeyName(): string
    {
        return 'id'; // Corresponds to the primary key in schema.sql
    }
}

/**
 * Class Post
 * Represents a blog post.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $title
 * @property string|null $content
 * @property string|null $published_at
 * @property string|null $created_at
 */
class Post extends Entity
{
    public ?int $id = null;
    public ?int $user_id = null;
    public ?string $title = null;
    public ?string $content = null;
    public ?string $published_at = null;
    public ?string $created_at = null;

    public function getTableName(): string
    {
        return 'posts';
    }

    public function getPrimaryKeyName(): string
    {
        return 'id';
    }
}


echo "User and Post entity classes defined." . PHP_EOL;
echo "These classes (Examples\\Entity\\User and Examples\\Entity\\Post) can now be used by other examples." . PHP_EOL;
echo "Note: This file itself doesn't perform actions other than defining classes." . PHP_EOL;
echo "Make sure you have a corresponding 'users' and 'posts' table in your database (see schema.sql)." . PHP_EOL;

echo PHP_EOL . "--- End of Example 02 ---" . PHP_EOL;

// This line is important if other example files directly include this one
// to make the classes available in their scope.
// However, with Composer's PSR-4 autoloading, direct inclusion isn't strictly necessary
// if the namespace `Examples` is correctly configured or if `bootstrap.php` handles it.
// For simplicity of these examples, other files will `require_once __DIR__ . '/02-define-entity.php';`
// after `bootstrap.php` to ensure these entity classes are loaded.
?>
