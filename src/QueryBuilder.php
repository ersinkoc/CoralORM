<?php

declare(strict_types=1);

namespace CoralORM;

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
    private array $groupByColumns = [];
    private ?string $havingCondition = null; // For simplicity, one raw string condition first
    private array $joinClauses = [];
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
     * @param string|array $columns The column to order by, or an array of [column => direction].
     * @param string $direction The sort direction ('ASC' or 'DESC'), used if $columns is a string.
     * @return self
     * @throws \InvalidArgumentException If direction is invalid or input is malformed.
     */
    public function orderBy(string|array $columns, string $direction = 'ASC'): self
    {
        if (is_string($columns)) {
            $direction = strtoupper($direction);
            if (!in_array($direction, ['ASC', 'DESC'])) {
                throw new \InvalidArgumentException("Invalid ORDER BY direction: {$direction}. Must be 'ASC' or 'DESC'.");
            }
            $this->orderByClauses[] = "{$columns} {$direction}";
        } elseif (is_array($columns)) {
            foreach ($columns as $column => $dir) {
                if (is_int($column)) { // Allows orderBy(['column1', 'column2 DESC']) but not recommended
                    // Attempt to parse column and direction if direction is part of the string
                    $parts = preg_split('/\s+/', trim($dir));
                    $colName = $parts[0];
                    $colDir = strtoupper($parts[1] ?? 'ASC');
                     if (!in_array($colDir, ['ASC', 'DESC'])) {
                        throw new \InvalidArgumentException("Invalid ORDER BY direction '{$colDir}' for column '{$colName}'. Must be 'ASC' or 'DESC'.");
                    }
                    $this->orderByClauses[] = "{$colName} {$colDir}";
                } else {
                    $dir = strtoupper($dir);
                    if (!in_array($dir, ['ASC', 'DESC'])) {
                        throw new \InvalidArgumentException("Invalid ORDER BY direction '{$dir}' for column '{$column}'. Must be 'ASC' or 'DESC'.");
                    }
                    $this->orderByClauses[] = "{$column} {$dir}";
                }
            }
        } else {
            throw new \InvalidArgumentException("orderBy expects a string column name or an array of [column => direction].");
        }
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

    // --- Aggregate Functions ---

    /**
     * Sets the SELECT clause to a COUNT aggregate.
     * @param string $column The column to count. Defaults to '*'.
     * @return self
     */
    public function count(string $column = '*'): self
    {
        $this->queryType = 'SELECT';
        $alias = "count_{$column}";
        if ($column === '*') {
            $alias = 'count_all';
        } else {
             $alias = "count_" . str_replace(['.', '(', ')'], ['_', '', ''], $column);
        }
        $this->selectColumns = ["COUNT({$column}) AS {$alias}"];
        return $this;
    }

    /**
     * Sets the SELECT clause to a SUM aggregate.
     * @param string $column The column to sum.
     * @return self
     */
    public function sum(string $column): self
    {
        if (empty($column) || $column === '*') {
            throw new \InvalidArgumentException("Column must be specified for SUM aggregate.");
        }
        $this->queryType = 'SELECT';
        $alias = "sum_" . str_replace(['.', '(', ')'], ['_', '', ''], $column);
        $this->selectColumns = ["SUM({$column}) AS {$alias}"];
        return $this;
    }

    /**
     * Sets the SELECT clause to an AVG aggregate.
     * @param string $column The column to average.
     * @return self
     */
    public function avg(string $column): self
    {
        if (empty($column) || $column === '*') {
            throw new \InvalidArgumentException("Column must be specified for AVG aggregate.");
        }
        $this->queryType = 'SELECT';
        $alias = "avg_" . str_replace(['.', '(', ')'], ['_', '', ''], $column);
        $this->selectColumns = ["AVG({$column}) AS {$alias}"];
        return $this;
    }

    /**
     * Sets the SELECT clause to a MIN aggregate.
     * @param string $column The column to find the minimum of.
     * @return self
     */
    public function min(string $column): self
    {
        if (empty($column) || $column === '*') {
            throw new \InvalidArgumentException("Column must be specified for MIN aggregate.");
        }
        $this->queryType = 'SELECT';
        $alias = "min_" . str_replace(['.', '(', ')'], ['_', '', ''], $column);
        $this->selectColumns = ["MIN({$column}) AS {$alias}"];
        return $this;
    }

    /**
     * Sets the SELECT clause to a MAX aggregate.
     * @param string $column The column to find the maximum of.
     * @return self
     */
    public function max(string $column): self
    {
        if (empty($column) || $column === '*') {
            throw new \InvalidArgumentException("Column must be specified for MAX aggregate.");
        }
        $this->queryType = 'SELECT';
        $alias = "max_" . str_replace(['.', '(', ')'], ['_', '', ''], $column);
        $this->selectColumns = ["MAX({$column}) AS {$alias}"];
        return $this;
    }

    /**
     * Adds a JOIN clause to the query.
     *
     * @param string $table The table to join with.
     * @param string $condition The ON condition for the join (e.g., 'users.id = posts.user_id').
     * @param string $type The type of join (INNER, LEFT, RIGHT, etc.).
     * @return self
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        $type = strtoupper($type);
        $allowedTypes = ['INNER', 'LEFT', 'RIGHT', 'FULL OUTER', 'CROSS']; // Add more if needed
        if (!in_array($type, $allowedTypes)) {
            throw new \InvalidArgumentException("Unsupported JOIN type: {$type}");
        }
        $this->joinClauses[] = [
            'table' => $table,
            'condition' => $condition,
            'type' => $type,
        ];
        return $this;
    }

    /**
     * Adds a LEFT JOIN clause to the query.
     *
     * @param string $table The table to join with.
     * @param string $condition The ON condition for the join.
     * @return self
     */
    public function leftJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'LEFT');
    }

    /**
     * Adds a RIGHT JOIN clause to the query.
     *
     * @param string $table The table to join with.
     * @param string $condition The ON condition for the join.
     * @return self
     */
    public function rightJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    /**
     * Adds a GROUP BY clause to the query.
     *
     * @param string|array $columns Column name or array of column names to group by.
     * @return self
     */
    public function groupBy(string|array $columns): self
    {
        if (is_array($columns)) {
            $this->groupByColumns = array_merge($this->groupByColumns, $columns);
        } else {
            $this->groupByColumns[] = $columns;
        }
        $this->groupByColumns = array_unique($this->groupByColumns); // Avoid duplicates
        return $this;
    }

    /**
     * Adds a HAVING clause to the query.
     * Note: Parameters in the having condition must be managed manually or use literal values.
     * For simplicity, this version accepts a raw string.
     *
     * @param string $conditions The HAVING condition string.
     * @return self
     */
    public function having(string $conditions): self
    {
        // For more advanced use, this could parse conditions and manage parameters like where()
        $this->havingCondition = $conditions;
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

        $sqlParts = [];
        $sqlParts[] = "SELECT " . implode(', ', $this->selectColumns);
        $sqlParts[] = "FROM {$this->fromTable}";

        if ($this->fromAlias) {
            $sqlParts[] = "AS {$this->fromAlias}";
        }

        // JOINs
        if (!empty($this->joinClauses)) {
            foreach($this->joinClauses as $join) {
                $sqlParts[] = strtoupper($join['type']) . " JOIN {$join['table']} ON {$join['condition']}";
            }
        }

        if (!empty($this->whereClauses)) {
            $whereParts = [];
            foreach ($this->whereClauses as $i => $clause) {
                $prefix = ($i > 0) ? "{$clause['boolean']} " : "";
                $whereParts[] = $prefix . "{$clause['column']} {$clause['operator']} {$clause['value_placeholder']}";
            }
            $sqlParts[] = "WHERE " . implode('', $whereParts);
        }

        if (!empty($this->groupByColumns)) {
            $sqlParts[] = "GROUP BY " . implode(', ', $this->groupByColumns);
        }

        if ($this->havingCondition !== null) {
            $sqlParts[] = "HAVING " . $this->havingCondition;
        }

        if (!empty($this->orderByClauses)) {
            $sqlParts[] = "ORDER BY " . implode(', ', $this->orderByClauses);
        }

        if ($this->limitValue !== null) {
            $sqlParts[] = "LIMIT " . $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $sqlParts[] = "OFFSET " . $this->offsetValue;
        }

        return implode(' ', $sqlParts);
    }


    /**
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
        $this->joinClauses = [];
        $this->whereClauses = [];
        $this->groupByColumns = [];
        $this->havingCondition = null;
        $this->orderByClauses = [];
        $this->limitValue = null;
        $this->offsetValue = null;
        $this->parameters = [];
        $this->paramCount = 0;
        $this->actionTable = '';
        $this->actionData = [];
    }
}
