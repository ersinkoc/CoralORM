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
            "    `comment_count` INT DEFAULT 0,\n" .
            "    `is_published` BOOLEAN DEFAULT 1,\n" .
            "    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n" .
            "    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with($expectedSql);

        $this->schemaBuilder->createTable('posts', function (SchemaBuilder $table) {
            $table->id(); // `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
            $table->string('title')->nullable(false)->default('Untitled'); // `title` VARCHAR(255) NOT NULL DEFAULT 'Untitled'
            $table->string('slug', 100)->unique(); // `slug` VARCHAR(100) UNIQUE
            $table->text('body')->nullable();      // `body` TEXT NULL
            $table->integer('comment_count')->default(0); // `comment_count` INT DEFAULT 0
            $table->boolean('is_published')->default(true); // `is_published` BOOLEAN DEFAULT 1
            $table->timestamps(); // created_at, updated_at
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
