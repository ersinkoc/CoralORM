<?php

declare(strict_types=1);

namespace Tests\YourOrm\Migration;

use YourOrm\Connection;
use YourOrm\Migration\SchemaBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class SchemaBuilderTest extends TestCase
{
    private MockObject|Connection $connectionMock;
    private SchemaBuilder $schemaBuilder;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(Connection::class);
        $this->schemaBuilder = new SchemaBuilder($this->connectionMock);
    }

    public function testCreateTableBasic()
    {
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with($this->stringContains("CREATE TABLE `users`"));

        $this->schemaBuilder->createTable('users', function (SchemaBuilder $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function testCreateTableWithAllColumnTypesAndModifiers()
    {
        $expectedSql = "CREATE TABLE `posts` (\n" .
            "    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n" .
            "    `title` VARCHAR(255) NOT NULL DEFAULT 'Untitled',\n" .
            "    `slug` VARCHAR(100) UNIQUE,\n" .
            "    `body` TEXT NULL,\n" .
            "    `publish_date` DATE NULL,\n" .  // New: DATE
            "    `event_time` DATETIME NULL,\n" . // New: DATETIME
            "    `value` DECIMAL(10,2) UNSIGNED DEFAULT 0.00,\n" . // New: DECIMAL, UNSIGNED
            "    `rating` FLOAT(8,2) NULL,\n" . // New: FLOAT
            "    `comment_count` INT UNSIGNED DEFAULT 0,\n" . // Modified: UNSIGNED
            "    `is_published` BOOLEAN DEFAULT 1,\n" .
            "    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n" .
            "    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with($expectedSql);

        $this->schemaBuilder->createTable('posts', function (SchemaBuilder $table) {
            $table->id();
            $table->string('title')->nullable(false)->default('Untitled');
            $table->string('slug', 100)->unique();
            $table->text('body')->nullable();
            $table->date('publish_date')->nullable(); // New
            $table->datetime('event_time')->nullable(); // New
            $table->decimal('value', 10, 2)->unsigned()->default(0.00); // New
            $table->float('rating', 8, 2)->nullable(); // New
            $table->integer('comment_count')->unsigned()->default(0); // Modified
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function testDropTable()
    {
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with("DROP TABLE `tasks`;");
        $this->schemaBuilder->dropTable('tasks');
    }

    public function testDropTableIfExists()
    {
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with("DROP TABLE IF EXISTS `tasks`;");
        $this->schemaBuilder->dropTableIfExists('tasks');
    }

    public function testChangeColumn()
    {
        // 1. Change type, nullability, default
        $this->connectionMock->expects($this->exactly(3)) // Expect 3 ALTER TABLE statements
            ->method('execute')
            ->withConsecutive(
                [$this->equalTo("ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(191) NOT NULL DEFAULT 'new@example.com';")],
                // 2. Rename column
                [$this->equalTo("ALTER TABLE `users` CHANGE COLUMN `old_name` `new_name` VARCHAR(255) NULL;")],
                // 3. Add unsigned and change type
                [$this->equalTo("ALTER TABLE `products` MODIFY COLUMN `quantity` INT UNSIGNED NOT NULL DEFAULT 0;")]
            );

        $this->schemaBuilder->changeColumn('users', 'email', [
            'type' => 'string',
            'length' => 191,
            'nullable' => false,
            'default' => 'new@example.com'
        ]);

        $this->schemaBuilder->changeColumn('users', 'old_name', [
            'name' => 'new_name', // Renaming
            'type' => 'string',   // Must provide type definition even if only renaming in this builder
            'length' => 255,
            'nullable' => true    // Full definition required
        ]);

        $this->schemaBuilder->changeColumn('products', 'quantity', [
            'type' => 'integer',
            'unsigned' => true,
            'nullable' => false,
            'default' => 0
        ]);
    }

    public function testAddAndDropForeignKeyConstraint()
    {
        $this->connectionMock->expects($this->exactly(2))
            ->method('execute')
            ->withConsecutive(
                [$this->stringContains("ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_user_id__users_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT;")],
                [$this->equalTo("ALTER TABLE `posts` DROP FOREIGN KEY `fk_posts_user_id__users_id`;")]
            );

        $this->schemaBuilder->addForeignKeyConstraint(
            'posts',
            'user_id',
            'users',
            'id',
            'fk_posts_user_id__users_id', // Explicit name
            'CASCADE' // onDelete
            // onUpdate defaults to RESTRICT
        );

        $this->schemaBuilder->dropForeignKeyConstraint('posts', 'fk_posts_user_id__users_id');
    }

    public function testAddForeignKeyConstraintWithAutoGeneratedName()
    {
         $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with($this->matchesRegularExpression('/ALTER TABLE `comments` ADD CONSTRAINT `fk_comments_post_id__posts_id_[a-f0-9]{32}` FOREIGN KEY \(`post_id`\) REFERENCES `posts` \(`id`\) ON DELETE RESTRICT ON UPDATE RESTRICT;/'));

        $this->schemaBuilder->addForeignKeyConstraint(
            'comments',
            'post_id',
            'posts',
            'id'
            // No constraint name, onUpdate/onDelete default to RESTRICT
        );
    }


    public function testColumnDefinitionOrderOfModifiers()
    {
        // Test specific case for default after nullable(false)
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with($this->stringContains("`status` VARCHAR(50) DEFAULT 'pending' NOT NULL"));

        $this->schemaBuilder->createTable('items', function (SchemaBuilder $table) {
            $table->string('status', 50)->nullable(false)->default('pending');
        });
    }
}
