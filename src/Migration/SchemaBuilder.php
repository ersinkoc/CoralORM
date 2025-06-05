<?php

declare(strict_types=1);

namespace YourOrm\Migration;

use YourOrm\Connection;
use Closure;

class SchemaBuilder
{
    protected Connection $connection;
    protected string $currentTable;
    protected array $statements = [];
    protected array $currentTableColumns = [];
    protected string $currentTableEngine = 'InnoDB'; // Default engine
    protected string $currentTableCharset = 'utf8mb4'; // Default charset
    protected string $currentTableCollation = 'utf8mb4_unicode_ci'; // Default collation


    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function createTable(string $tableName, Closure $callback): void
    {
        $this->currentTable = $tableName;
        $this->currentTableColumns = []; // Reset for current table
        $this->statements = []; // Reset statements for this operation

        $callback($this); // Populate $this->currentTableColumns via methods like id(), string() etc.

        $columnsSQL = implode(",\n    ", $this->currentTableColumns);
        $sql = "CREATE TABLE `{$tableName}` (\n    {$columnsSQL}\n) ENGINE={$this->currentTableEngine} DEFAULT CHARSET={$this->currentTableCharset} COLLATE={$this->currentTableCollation};";
        $this->statements[] = $sql;
        $this->executeStatements();
        $this->currentTableColumns = []; // Clear after use
    }

    public function dropTable(string $tableName): void
    {
        $this->statements[] = "DROP TABLE `{$tableName}`;";
        $this->executeStatements();
    }

    public function dropTableIfExists(string $tableName): void
    {
        $this->statements[] = "DROP TABLE IF EXISTS `{$tableName}`;";
        $this->executeStatements();
    }

    // Column methods (called within createTable callback)
    public function id(string $columnName = 'id'): self
    {
        $this->currentTableColumns[] = "`{$columnName}` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
        return $this;
    }

    public function string(string $columnName, int $length = 255): self
    {
        $this->currentTableColumns[] = "`{$columnName}` VARCHAR({$length})";
        return $this;
    }

    public function text(string $columnName): self
    {
        $this->currentTableColumns[] = "`{$columnName}` TEXT";
        return $this;
    }

    public function integer(string $columnName): self
    {
        $this->currentTableColumns[] = "`{$columnName}` INT";
        return $this;
    }

    public function boolean(string $columnName): self
    {
        $this->currentTableColumns[] = "`{$columnName}` BOOLEAN"; // TINYINT(1) in MySQL
        return $this;
    }

    public function timestamps(?string $createdAtColumn = 'created_at', ?string $updatedAtColumn = 'updated_at'): self
    {
        if ($createdAtColumn) {
            $this->currentTableColumns[] = "`{$createdAtColumn}` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP";
        }
        if ($updatedAtColumn) {
            // MySQL specific for auto-update
            $this->currentTableColumns[] = "`{$updatedAtColumn}` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        }
        return $this;
    }

    // Column Modifiers (applied to the last added column)
    // These are more complex as they need to modify the last added column string.
    // A more robust way is to store column definitions as arrays and build the string at the end.
    // For now, simple string append for a few.

    public function nullable(bool $isNullable = true): self
    {
        if (!empty($this->currentTableColumns)) {
            $lastColumnIndex = count($this->currentTableColumns) - 1;
            if ($isNullable) {
                if (!str_contains($this->currentTableColumns[$lastColumnIndex], 'PRIMARY KEY')) { // PK cannot be null
                     $this->currentTableColumns[$lastColumnIndex] .= " NULL";
                }
            } else {
                $this->currentTableColumns[$lastColumnIndex] .= " NOT NULL";
            }
        }
        return $this;
    }

    public function default(mixed $value): self
    {
        if (!empty($this->currentTableColumns)) {
            $lastColumnIndex = count($this->currentTableColumns) - 1;
            if (is_string($value)) {
                $value = "'{$value}'";
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = 'NULL';
            }
            // Ensure NOT NULL comes before DEFAULT if nullable(false) was called before.
            if (str_ends_with($this->currentTableColumns[$lastColumnIndex], " NOT NULL")) {
                $this->currentTableColumns[$lastColumnIndex] = str_replace(" NOT NULL", "", $this->currentTableColumns[$lastColumnIndex]);
                $this->currentTableColumns[$lastColumnIndex] .= " DEFAULT {$value} NOT NULL";
            } else {
                $this->currentTableColumns[$lastColumnIndex] .= " DEFAULT {$value}";
            }
        }
        return $this;
    }

    public function unique(): self
    {
         if (!empty($this->currentTableColumns)) {
            $lastColumnIndex = count($this->currentTableColumns) - 1;
            // This is a constraint, better added at table level for multi-column uniques
            // For single column, can be inline
            $this->currentTableColumns[$lastColumnIndex] .= " UNIQUE";
        }
        return $this;
    }

    protected function executeStatements(): void
    {
        // In a real scenario, you might want to wrap these in a transaction if supported for DDL.
        // For MySQL, DDL statements cause an implicit commit.
        foreach ($this->statements as $statement) {
            // In a real application, log the statement here
            // echo "Executing SQL: {$statement}\n";
            $this->connection->execute($statement);
        }
        $this->statements = []; // Clear after execution
    }
}
