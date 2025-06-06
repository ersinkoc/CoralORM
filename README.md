# PHP ORM

A simple and lightweight Object-Relational Mapper (ORM) for PHP.

## Features

*   **Entities**: Represent database tables as PHP objects.
*   **Repositories**: Provide a way to interact with entities and perform database operations.
*   **Query Builder**: A fluent interface for building complex SQL queries.
*   **Migrations**: Manage database schema changes over time.

## Installation

To install the ORM, you will need Composer. If you don't have Composer installed, you can download it from [getcomposer.org](https://getcomposer.org/).

Once you have Composer installed, you can add the ORM as a dependency to your project:

```bash
composer require your-vendor/php-orm
```

## Usage

Here's a simple example of how to use the ORM:

```php
<?php

require_once 'vendor/autoload.php';

use YourVendor\PhpOrm\EntityManager;
use YourVendor\PhpOrm\Entity\User;

// Configure the entity manager
$entityManager = new EntityManager([
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
]);

// Create a new user
$user = new User();
$user->setName('John Doe');
$user->setEmail('john.doe@example.com');

// Persist the user to the database
$entityManager->persist($user);
$entityManager->flush();

// Find a user by ID
$foundUser = $entityManager->getRepository(User::class)->find(1);

if ($foundUser) {
    echo "Found user: " . $foundUser->getName() . "\n";
}

?>
```

This is a basic example, and the ORM offers more advanced features like relationships, query building, and migrations. Refer to the documentation for more details.
```
