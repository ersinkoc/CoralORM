<?php

declare(strict_types=1);

namespace CoralORM\Migration\Database; // Critical namespace

use CoralORM\Migration\AbstractMigration;
use CoralORM\Migration\SchemaBuilder;

/**
 * Example migration to create an 'example_items' table.
 */
class CreateExampleItemsTable_20240315000000 extends AbstractMigration // Class name convention for Migrator
{
    public function up(SchemaBuilder $schema): void
    {
        echo "Applying migration: CreateExampleItemsTable_20240315000000 UP" . PHP_EOL;
        $schema->createTable('example_items', function(SchemaBuilder $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('quantity')->default(0);
            $table->timestamps(); // Adds created_at and updated_at
        });
        echo "Table 'example_items' created." . PHP_EOL;
    }

    public function down(SchemaBuilder $schema): void
    {
        echo "Reverting migration: CreateExampleItemsTable_20240315000000 DOWN" . PHP_EOL;
        $schema->dropTableIfExists('example_items');
        echo "Table 'example_items' dropped." . PHP_EOL;
    }
}
