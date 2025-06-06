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
    // Stores column definitions as arrays before compiling to SQL string for createTable
    // Example: [['name' => 'id', 'type' => 'BIGINT', 'options' => ['UNSIGNED', 'AUTO_INCREMENT', 'PRIMARY KEY']], ...]
    protected array $pendingColumns = [];
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
        $this->pendingColumns = [];
        $this->statements = [];

        $callback($this); // This will now populate $this->pendingColumns

        $columnDefinitions = [];
        foreach ($this->pendingColumns as $column) {
            $sql = "`{$column['name']}` {$column['type']}";
            if (!empty($column['options'])) {
                // Handle specific order for certain options if needed, e.g., NOT NULL before DEFAULT
                // For now, simple implode.
                // Example: UNSIGNED, NOT NULL, DEFAULT 'value', UNIQUE, PRIMARY KEY, AUTO_INCREMENT
                // A more sophisticated builder would order these correctly.
                // Let's try a basic order: type -> unsigned -> nullable -> default -> unique -> pk -> ai
                $optionOrder = ['UNSIGNED', 'NOT NULL', 'NULL', 'DEFAULT', 'UNIQUE', 'PRIMARY KEY', 'AUTO_INCREMENT'];
                $sortedOptions = [];

                // Default value needs special handling for quotes
                $defaultIndex = -1;
                foreach($column['options'] as $i => $opt) {
                    if (is_array($opt) && $opt[0] === 'DEFAULT') {
                        $defaultIndex = $i;
                        break;
                    }
                }

                foreach($optionOrder as $key) {
                    if ($key === 'DEFAULT' && $defaultIndex !== -1) {
                        $sortedOptions[] = "DEFAULT " . $this->quoteDefaultValue($column['options'][$defaultIndex][1]);
                        // Remove it so it's not processed again if other options are simple strings
                        unset($column['options'][$defaultIndex]);
                    } elseif (in_array($key, $column['options'])) {
                         $sortedOptions[] = $key;
                    }
                }
                // Add any other options that were not in $optionOrder (e.g. custom options)
                // though currently all are covered or should be.
                $sql .= " " . implode(' ', $sortedOptions);
            }
            $columnDefinitions[] = $sql;
        }

        $columnsSQL = implode(",\n    ", $columnDefinitions);
        $sql = "CREATE TABLE `{$tableName}` (\n    {$columnsSQL}\n) ENGINE={$this->currentTableEngine} DEFAULT CHARSET={$this->currentTableCharset} COLLATE={$this->currentTableCollation};";
        $this->statements[] = $sql;
        $this->executeStatements();
        $this->pendingColumns = [];
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
    private function addColumnDefinition(string $name, string $type, array $options = []): self
    {
        $this->pendingColumns[] = ['name' => $name, 'type' => $type, 'options' => $options];
        return $this;
    }

    private function quoteDefaultValue(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return 'NULL';
        }
        return (string)$value; // Numbers
    }

    // Column type methods
    public function id(string $columnName = 'id'): self
    {
        return $this->addColumnDefinition($columnName, 'BIGINT', ['UNSIGNED', 'AUTO_INCREMENT', 'PRIMARY KEY']);
    }

    public function string(string $columnName, int $length = 255): self
    {
        return $this->addColumnDefinition($columnName, "VARCHAR({$length})");
    }

    public function text(string $columnName): self
    {
        return $this->addColumnDefinition($columnName, 'TEXT');
    }

    public function integer(string $columnName): self
    {
        return $this->addColumnDefinition($columnName, 'INT');
    }

    public function boolean(string $columnName): self
    {
        // In MySQL, BOOLEAN is an alias for TINYINT(1).
        // Other databases might have a true BOOLEAN type.
        return $this->addColumnDefinition($columnName, 'BOOLEAN');
    }

    public function date(string $columnName): self
    {
        return $this->addColumnDefinition($columnName, 'DATE');
    }

    public function datetime(string $columnName): self
    {
        // Consider precision for other DBs, MySQL default is 0 for DATETIME.
        return $this->addColumnDefinition($columnName, 'DATETIME');
    }

    public function float(string $columnName, ?int $precision = null, ?int $scale = null): self
    {
        $type = 'FLOAT';
        if ($precision !== null && $scale !== null) {
             $type = "FLOAT({$precision},{$scale})"; // FLOAT(p,s) is valid but might become DOUBLE or DECIMAL
        } elseif ($precision !== null) {
            // FLOAT(p) where p <= 24 is FLOAT, p > 24 is DOUBLE
            $type = "FLOAT({$precision})";
        }
        return $this->addColumnDefinition($columnName, $type);
    }

    public function decimal(string $columnName, ?int $precision = 8, ?int $scale = 2): self
    {
        // DECIMAL(p,s) - precision (total digits), scale (digits after decimal point)
        $type = 'DECIMAL';
        if ($precision !== null && $scale !== null) {
            $type = "DECIMAL({$precision},{$scale})";
        }
        return $this->addColumnDefinition($columnName, $type);
    }

    public function timestamps(?string $createdAtColumn = 'created_at', ?string $updatedAtColumn = 'updated_at'): self
    {
        if ($createdAtColumn) {
            $this->addColumnDefinition($createdAtColumn, 'TIMESTAMP', [['DEFAULT', 'CURRENT_TIMESTAMP'], 'NULL']);
        }
        if ($updatedAtColumn) {
            // MySQL specific for auto-update
            $this->addColumnDefinition($updatedAtColumn, 'TIMESTAMP', [['DEFAULT', 'CURRENT_TIMESTAMP'], 'ON UPDATE CURRENT_TIMESTAMP', 'NULL']);
        }
        return $this;
    }

    // Column Modifiers (applied to the last added column definition in $this->pendingColumns)
    private function addOptionToLastColumn(string|array $option): self
    {
        if (empty($this->pendingColumns)) {
            throw new \LogicException("Cannot apply modifier: No column definition started yet.");
        }
        $lastColumnIndex = count($this->pendingColumns) - 1;
        // Avoid duplicate options like multiple NULL/NOT NULL or DEFAULTs
        // More robust checking might be needed if options can conflict
        $this->pendingColumns[$lastColumnIndex]['options'][] = $option;
        return $this;
    }

    public function nullable(bool $isNullable = true): self
    {
        // Remove previous nullability constraints if any to avoid conflicts like "NULL NOT NULL"
        if (!empty($this->pendingColumns)) {
            $lastColumnIndex = count($this->pendingColumns) - 1;
            $this->pendingColumns[$lastColumnIndex]['options'] = array_filter(
                $this->pendingColumns[$lastColumnIndex]['options'],
                fn($opt) => $opt !== 'NULL' && $opt !== 'NOT NULL'
            );
        }
        return $this->addOptionToLastColumn($isNullable ? 'NULL' : 'NOT NULL');
    }

    public function default(mixed $value): self
    {
         // Remove previous default constraint if any
        if (!empty($this->pendingColumns)) {
            $lastColumnIndex = count($this->pendingColumns) - 1;
            $this->pendingColumns[$lastColumnIndex]['options'] = array_filter(
                $this->pendingColumns[$lastColumnIndex]['options'],
                fn($opt) => !(is_array($opt) && $opt[0] === 'DEFAULT')
            );
        }
        return $this->addOptionToLastColumn(['DEFAULT', $value]);
    }

    public function unique(): self
    {
        return $this->addOptionToLastColumn('UNIQUE');
    }

    public function unsigned(): self
    {
        // Typically for INT, BIGINT, DECIMAL, FLOAT etc.
        // The builder should ideally check if type is compatible.
        return $this->addOptionToLastColumn('UNSIGNED');
    }

    /**
     * Modifies an existing column in a table.
     * MySQL syntax will be primarily targetted: ALTER TABLE `tableName` CHANGE COLUMN `oldColumnName` `newColumnName` <definition>
     * or ALTER TABLE `tableName` MODIFY COLUMN `columnName` <definition>
     * For simplicity, if 'name' is provided in $options, it's considered a rename (CHANGE COLUMN), otherwise MODIFY COLUMN.
     *
     * @param string $tableName The name of the table.
     * @param string $columnName The current name of the column to modify.
     * @param array $options Array of options for the new column definition.
     *                        Keys can include: 'name' (for new name), 'type', 'length',
     *                        'nullable', 'default', 'unsigned', 'autoIncrement', 'primaryKey', 'unique'.
     *                        'type' should be one of 'string', 'integer', 'text', etc.
     */
    public function changeColumn(string $tableName, string $columnName, array $options): void
    {
        $newColumnName = $options['name'] ?? $columnName;
        $type = $options['type'] ?? null; // e.g., 'string', 'integer'

        if (!$type && !isset($options['nullable']) && !isset($options['default']) && !($newColumnName !== $columnName)) {
            // Or if only name changes, what is the type? We need full definition.
            // This method expects a full new definition of the column, not partial modification.
            throw new \InvalidArgumentException("changeColumn requires at least a new type or a new name with its original type specified, or other modifiers.");
        }

        // Build the new column definition string part
        // This is tricky because we need to map high-level types like 'string' to SQL types like VARCHAR(255)
        // And also apply all modifiers like NOT NULL, DEFAULT etc.
        // We can reuse the logic from createTable's column building if we adapt it.

        // Temporary column definition structure
        $columnDef = ['name' => $newColumnName];
        $colOptions = [];

        // Determine SQL type
        if ($type) {
            switch (strtolower($type)) {
                case 'string':
                    $columnDef['type'] = "VARCHAR(" . ($options['length'] ?? 255) . ")";
                    break;
                case 'text':
                    $columnDef['type'] = "TEXT";
                    break;
                case 'integer':
                    $columnDef['type'] = "INT";
                    break;
                case 'bigint': // Often used for IDs
                    $columnDef['type'] = "BIGINT";
                    break;
                case 'boolean':
                    $columnDef['type'] = "BOOLEAN"; // TINYINT(1) in MySQL
                    break;
                case 'date':
                    $columnDef['type'] = "DATE";
                    break;
                case 'datetime':
                    $columnDef['type'] = "DATETIME";
                    break;
                case 'float':
                    $p = $options['precision'] ?? null;
                    $s = $options['scale'] ?? null;
                    $columnDef['type'] = "FLOAT" . ($p && $s ? "({$p},{$s})" : ($p ? "({$p})" : ""));
                    break;
                case 'decimal':
                    $p = $options['precision'] ?? 8;
                    $s = $options['scale'] ?? 2;
                    $columnDef['type'] = "DECIMAL({$p},{$s})";
                    break;
                default:
                    // Allow raw SQL type if not a mapped one
                    $columnDef['type'] = strtoupper($type);
            }
        } else {
            // If type is not changing, we need to know the original type.
            // This simple SchemaBuilder doesn't query DB schema (introspection).
            // So, the user *must* provide the type if they are changing anything other than name.
            // If only name changes, they still need to provide the original type.
            throw new \InvalidArgumentException("The 'type' option is required for changeColumn to define the column structure.");
        }

        if (isset($options['unsigned']) && $options['unsigned']) {
            $colOptions[] = 'UNSIGNED';
        }
        if (isset($options['nullable'])) {
            $colOptions[] = $options['nullable'] ? 'NULL' : 'NOT NULL';
        }
        if (array_key_exists('default', $options)) { // Check explicitly for default, as value can be null
             $colOptions[] = ['DEFAULT', $options['default']];
        }
        if (isset($options['unique']) && $options['unique']) {
            $colOptions[] = 'UNIQUE';
        }
        if (isset($options['autoIncrement']) && $options['autoIncrement']) {
            $colOptions[] = 'AUTO_INCREMENT';
        }
        if (isset($options['primaryKey']) && $options['primaryKey']) {
            $colOptions[] = 'PRIMARY KEY';
        }

        // Similar logic to createTable for ordering options
        $optionOrder = ['UNSIGNED', 'NOT NULL', 'NULL', 'DEFAULT', 'UNIQUE', 'PRIMARY KEY', 'AUTO_INCREMENT'];
        $sortedOptionsSQL = [];
        $defaultValSQL = null;

        foreach($colOptions as $opt) {
            if (is_array($opt) && $opt[0] === 'DEFAULT') {
                $defaultValSQL = "DEFAULT " . $this->quoteDefaultValue($opt[1]);
                continue;
            }
            // simple string options
            if(in_array($opt, $optionOrder)) $sortedOptionsSQL[] = $opt;
        }

        $finalOptionsString = "";
        $tempSorted = [];
        foreach($optionOrder as $key) {
            if($key === 'DEFAULT' && $defaultValSQL) {
                $tempSorted[] = $defaultValSQL;
            } elseif (in_array($key, $sortedOptionsSQL)) {
                $tempSorted[] = $key;
            }
        }
        $finalOptionsString = implode(' ', $tempSorted);

        $columnDefinitionSQL = "`{$newColumnName}` {$columnDef['type']}";
        if (!empty($finalOptionsString)) {
            $columnDefinitionSQL .= " " . $finalOptionsString;
        }

        // Choose between CHANGE COLUMN (if name changes) or MODIFY COLUMN
        $alterType = ($newColumnName !== $columnName) ? "CHANGE COLUMN `{$columnName}`" : "MODIFY COLUMN";

        $sql = "ALTER TABLE `{$tableName}` {$alterType} {$columnDefinitionSQL};";
        $this->statements[] = $sql;
        $this->executeStatements();
    }

    /**
     * Adds a foreign key constraint to a table.
     *
     * @param string $tableName The table to add the constraint to.
     * @param string $columnName The column(s) in the current table. Can be comma-separated for composite keys.
     * @param string $referencedTable The table referenced by the foreign key.
     * @param string $referencedColumn The column(s) in the referenced table. Can be comma-separated for composite keys.
     * @param string $constraintName Optional name for the constraint. If not provided, one will be generated.
     * @param string $onDelete Optional ON DELETE action (RESTRICT, CASCADE, SET NULL, NO ACTION).
     * @param string $onUpdate Optional ON UPDATE action (RESTRICT, CASCADE, SET NULL, NO ACTION).
     */
    public function addForeignKeyConstraint(
        string $tableName,
        string $columnName, // Could be multiple: 'col1, col2'
        string $referencedTable,
        string $referencedColumn, // Could be multiple: 'ref_col1, ref_col2'
        ?string $constraintName = null,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT'
    ): void {
        $localColumns = '`' . str_replace(',', '`, `', $columnName) . '`';
        $foreignColumns = '`' . str_replace(',', '`, `', $referencedColumn) . '`';

        if (!$constraintName) {
            $constraintName = "fk_{$tableName}_{$columnName}__{$referencedTable}_{$referencedColumn}";
            // Sanitize constraint name (remove invalid characters, ensure length)
            $constraintName = preg_replace('/[^a-zA-Z0-9_]/', '', $constraintName);
            if (strlen($constraintName) > 64) { // MySQL limit
                $constraintName = substr($constraintName, 0, 50) . '_' . md5($constraintName); // Ensure uniqueness if truncated
            }
        }

        $sql = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$constraintName}` ";
        $sql .= "FOREIGN KEY ({$localColumns}) REFERENCES `{$referencedTable}` ({$foreignColumns})";

        $onDelete = strtoupper($onDelete);
        $onUpdate = strtoupper($onUpdate);
        $validActions = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT']; // SET DEFAULT might not be widely supported or straightforward

        if (in_array($onDelete, $validActions)) {
            $sql .= " ON DELETE {$onDelete}";
        }
        if (in_array($onUpdate, $validActions)) {
            $sql .= " ON UPDATE {$onUpdate}";
        }
        $sql .= ";";

        $this->statements[] = $sql;
        $this->executeStatements();
    }

    /**
     * Drops a foreign key constraint from a table.
     *
     * @param string $tableName The table to remove the constraint from.
     * @param string $constraintName The name of the foreign key constraint to drop.
     */
    public function dropForeignKeyConstraint(string $tableName, string $constraintName): void
    {
        // MySQL uses: ALTER TABLE `tableName` DROP FOREIGN KEY `constraintName`;
        // Other DBs might use: ALTER TABLE `tableName` DROP CONSTRAINT `constraintName`;
        // Assuming MySQL for now.
        $sql = "ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$constraintName}`;";
        $this->statements[] = $sql;
        $this->executeStatements();
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
