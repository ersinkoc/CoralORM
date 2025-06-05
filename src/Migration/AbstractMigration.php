<?php

declare(strict_types=1);

namespace YourOrm\Migration;

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
}
