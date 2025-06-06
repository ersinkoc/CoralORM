<?php

declare(strict_types=1);

namespace YourOrm\Migration;

use YourOrm\Connection;

/**
 * Base class for database migrations.
 */
abstract class AbstractMigration
{
    /**
     * Defines the schema changes to apply.
     *
     * @param SchemaBuilder $schema The schema builder instance.
     * @return void
     */
    abstract public function up(SchemaBuilder $schema): void;

    /**
     * Defines the schema changes to reverse.
     *
     * @param SchemaBuilder $schema The schema builder instance.
     * @return void
     */
    abstract public function down(SchemaBuilder $schema): void;

    /**
     * Optional method to perform actions after the 'up' migration is successfully applied.
     * This can be used for seeding data or other post-migration tasks.
     *
     * @param Connection $connection The database connection (if direct DB access is needed).
     * @return void
     */
    public function postUp(Connection $connection): void
    {
        // Default implementation is empty.
        // Migrations can override this method to perform actions.
    }
}
