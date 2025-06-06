<?php

declare(strict_types=1);

namespace YourOrm;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Builds SQL queries programmatically.
 */
class QueryBuilder
{
    private string $queryType = '';
    private array $selectColumns = ['*'];
    private string $fromTable = '';
    private ?string $fromAlias = null;
    private array $whereClauses = [];
    private array $orderByClauses = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private array $parameters = [];
    private int $paramCount = 0;

    // For INSERT/UPDATE
    private string $actionTable = '';
    private array $actionData = [];


    /**
     * QueryBuilder constructor.
     *
     * @param Connection $connection The database connection.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Specifies the columns to select.
     *
     * @param string ...$columns Columns to select. Defaults to '*'.
     * @return self
     */
    public function select(string ...$columns): self
    {
        $this->queryType = 'SELECT';
        $this->selectColumns = empty($columns) ? ['*'] : $columns;
        return $this;
    }

    /**
     * Specifies the table to select from.
     *
     * @param string $table The name of the table.
     * @param ?string $alias Optional alias for the table.
     * @return self
     */
    public function from(string $table, ?string $alias = null): self
    {
        $this->fromTable = $table;
        $this->fromAlias = $alias;
        return $this;
    }

    /**
     * Adds a WHERE clause to the query.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator (e.g., '=', '>', '<=', 'LIKE').
     * @param mixed $value The value to compare against.
     * @param string $boolean The boolean operator ('AND' or 'OR') to link with previous clauses.
     * @return self
     */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): self
    {
        $paramName = ":param" . ($this->paramCount++);
        $this->whereClauses[] = [
            'column' => $column,
            'operator' => $operator,
            'value_placeholder' => $paramName,
            'boolean' => count($this->whereClauses) > 0 ? $boolean : '' // No boolean for the first clause
        ];
        $this->parameters[$paramName] = $value;
        return $this;
    }

    /**
     * Adds an OR WHERE clause to the query.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return self
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * Adds an ORDER BY clause to the query.
     *
     * @param string $column The column to order by.
     * @param string $direction The sort direction ('ASC' or 'DESC').
     * @return self
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException("Invalid ORDER BY direction: {$direction}. Must be 'ASC' or 'DESC'.");
        }
        $this->orderByClauses[] = "{$column} {$direction}";
        return $this;
    }

    /**
     * Adds a LIMIT clause to the query.
     *
     * @param int $limit The maximum number of rows to return.
     * @return self
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException("LIMIT must be a non-negative integer.");
        }
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Adds an OFFSET clause to the query.
     *
     * @param int $offset The number of rows to skip.
     * @return self
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException("OFFSET must be a non-negative integer.");
        }
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Builds and returns the SQL query string for SELECT statements.
     *
     * @return string The generated SQL query.
     */
    private function buildSelectSql(): string
    {
        if (empty($this->fromTable)) {
            throw new \LogicException("Cannot build SELECT query without a FROM table.");
        }

        $sql = "SELECT " . implode(', ', $this->selectColumns) . " FROM {$this->fromTable}";

        if ($this->fromAlias) {
            $sql .= " AS {$this->fromAlias}";
        }

        if (!empty($this->whereClauses)) {
            $sql .= " WHERE ";
            foreach ($this->whereClauses as $i => $clause) {
                if ($i > 0) {
                    $sql .= " {$clause['boolean']} ";
                }
                $sql .= "{$clause['column']} {$clause['operator']} {$clause['value_placeholder']}";
            }
        }

        if (!empty($this->orderByClauses)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderByClauses);
        }

        if ($this->limitValue !== null) {
            $sql .= " LIMIT " . $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $sql .= " OFFSET " . $this->offsetValue;
        }

        return $sql;
    }


    /**
     * Gets the built SQL query string.
     *
     * @return string The SQL query string.
     * @throws \LogicException If the query cannot be built.
     */
    public function getSql(): string
    {
        switch ($this->queryType) {
            case 'SELECT':
                return $this->buildSelectSql();
            case 'INSERT':
                return $this->buildInsertSql();
            case 'UPDATE':
                return $this->buildUpdateSql();
            case 'DELETE':
                return $this->buildDeleteSql();
            default:
                throw new \LogicException("Query type not set or not supported for getSql(). Call select(), insert(), update(), or delete() first.");
        }
    }

    /**
     * Gets the bound parameters for the query.
     *
     * @return array<string, mixed> The parameters.
     */
    public function getParameters(): array
    {
        if ($this->queryType === 'INSERT') {
            $insertParams = [];
            foreach ($this->actionData as $key => $value) {
                $paramName = ":insert_" . $key;
                $insertParams[$paramName] = $value;
            }
            // For INSERT, parameters are built just-in-time and not merged with other types.
            return $insertParams;
        }
        // For SELECT, UPDATE, DELETE, parameters are already populated by where() or by buildUpdateSql()
        return $this->parameters;
    }

    /**
     * Executes the built SELECT query and returns a single row.
     *
     * @return ?array<string, mixed> An associative array for the row, or null if no result.
     * @throws PDOException If query execution fails.
     */
    public function fetch(): ?array
    {
        if ($this->queryType !== 'SELECT') {
            throw new \LogicException("fetch() can only be called for SELECT queries.");
        }
        $this->limit(1); // Ensure only one row is fetched
        $sql = $this->getSql();
        $stmt = $this->connection->execute($sql, $this->getParameters());
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();
        return $result ?: null;
    }

    /**
     * Executes the built SELECT query and returns all rows.
     *
     * @return array<int, array<string, mixed>> An array of associative arrays.
     * @throws PDOException If query execution fails.
     */
    public function fetchAll(): array
    {
        if ($this->queryType !== 'SELECT') {
            throw new \LogicException("fetchAll() can only be called for SELECT queries.");
        }
        $sql = $this->getSql();
        $stmt = $this->connection->execute($sql, $this->getParameters());
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->reset();
        return $result;
    }

    /**
     * Prepares an INSERT query.
     *
     * @param string $table The table to insert data into.
     * @param array<string, mixed> $data An associative array of column => value pairs.
     * @return self
     * @throws \InvalidArgumentException If data is empty.
     */
    public function insert(string $table, array $data): self
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Cannot insert empty data set.");
        }
        $this->queryType = 'INSERT';
        $this->actionTable = $table;
        $this->actionData = $data;
        $this->parameters = [];
        $this->paramCount = 0;
        return $this;
    }

     /**
     * Builds the SQL for an INSERT statement.
     * @return string The SQL INSERT statement.
     */
    private function buildInsertSql(): string
    {
        $columns = implode(', ', array_keys($this->actionData));
        $valuePlaceholders = [];
        foreach (array_keys($this->actionData) as $key) {
             $valuePlaceholders[] = ":insert_" . $key; // Match param names in insert()
        }
        $placeholders = implode(', ', $valuePlaceholders);
        return "INSERT INTO {$this->actionTable} ({$columns}) VALUES ({$placeholders})";
    }


    /**
     * Prepares an UPDATE query.
     *
     * @param string $table The table to update.
     * @param array<string, mixed> $data An associative array of column => value pairs to update.
     * @return self
     * @throws \InvalidArgumentException If data is empty.
     */
    public function update(string $table, array $data): self
    {
        $this->queryType = 'UPDATE';
        $this->actionTable = $table;
        $this->actionData = $data;
        $this->parameters = []; // Reset params, where clauses will add their own
        $this->paramCount = 0; // Reset for where clauses
        $this->whereClauses = []; // Crucial: reset where clauses for a new UPDATE

        if (empty($this->actionData)) {
            throw new \InvalidArgumentException("Cannot update with empty data set.");
        }
        return $this;
    }

    /**
     * Builds the SQL for an UPDATE statement.
     * @return string The SQL UPDATE statement.
     */
    private function buildUpdateSql(): string
    {
        $setClauses = [];
        // Parameters for SET part must be distinct from WHERE part if keys could overlap
        // We will prefix them with `set_`
        $updateParams = [];
        foreach ($this->actionData as $key => $value) {
            $paramName = ":set_" . $key;
            $setClauses[] = "{$key} = {$paramName}";
            $updateParams[$paramName] = $value;
        }

        // Merge with existing where parameters. Where params are already named :param0, :param1 etc.
        $this->parameters = array_merge($updateParams, $this->parameters);


        $sql = "UPDATE {$this->actionTable} SET " . implode(', ', $setClauses);

        if (!empty($this->whereClauses)) {
            $sql .= " WHERE ";
            foreach ($this->whereClauses as $i => $clause) {
                if ($i > 0) {
                    $sql .= " {$clause['boolean']} ";
                }
                $sql .= "{$clause['column']} {$clause['operator']} {$clause['value_placeholder']}";
            }
        } else {
            // Safety: Prevent updating all rows by mistake if no WHERE clause is specified.
            // Depending on desired behavior, this could throw an exception or be allowed.
            // For now, let's throw an exception if no WHERE clause is set for UPDATE.
            throw new \LogicException("UPDATE statement must have a WHERE clause. Call where() before execute().");
        }
        return $sql;
    }


    /**
     * Prepares a DELETE query.
     *
     * @param string $table The table to delete from.
     * @return self
     */
    public function delete(string $table): self
    {
        $this->queryType = 'DELETE';
        $this->actionTable = $table;
        $this->parameters = []; // Reset params, where clauses will add their own
        $this->paramCount = 0;
        $this->whereClauses = []; // Crucial: reset where clauses for a new DELETE
        return $this;
    }

    /**
     * Builds the SQL for a DELETE statement.
     * @return string The SQL DELETE statement.
     */
    private function buildDeleteSql(): string
    {
        $sql = "DELETE FROM {$this->actionTable}";
        if (!empty($this->whereClauses)) {
            $sql .= " WHERE ";
            foreach ($this->whereClauses as $i => $clause) {
                if ($i > 0) {
                    $sql .= " {$clause['boolean']} ";
                }
                $sql .= "{$clause['column']} {$clause['operator']} {$clause['value_placeholder']}";
            }
        } else {
            // Safety: Prevent deleting all rows by mistake if no WHERE clause is specified.
            throw new \LogicException("DELETE statement must have a WHERE clause. Call where() before execute().");
        }
        return $sql;
    }

    /**
     * Executes an UPDATE or DELETE query.
     *
     * @return bool True on success, false on failure.
     * @throws PDOException If query execution fails.
     * @throws \LogicException If the query type is not UPDATE or DELETE, or if conditions are not met (e.g. no WHERE for UPDATE/DELETE).
     */
    public function execute(): bool
    {
        if (!in_array($this->queryType, ['INSERT', 'UPDATE', 'DELETE'])) {
            throw new \LogicException("execute() can only be called for INSERT, UPDATE, or DELETE queries.");
        }

        $sql = $this->getSql();
        $params = $this->getParameters(); // For INSERT, this now generates params correctly.

        try {
            $this->connection->execute($sql, $params);
            $this->reset();
            return true;
        } catch (PDOException $e) {
            $this->reset();
            error_log("{$this->queryType} failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resets the query builder state for a new query.
     */
    private function reset(): void
    {
        $this->queryType = '';
        $this->selectColumns = ['*'];
        $this->fromTable = '';
        $this->fromAlias = null;
        $this->whereClauses = [];
        $this->orderByClauses = [];
        $this->limitValue = null;
        $this->offsetValue = null;
        $this->parameters = [];
        $this->paramCount = 0;
        $this->actionTable = '';
        $this->actionData = [];
    }
}
