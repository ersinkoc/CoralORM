<?php

declare(strict_types=1);

namespace YourOrm;

use PDO;
use PDOException;
use PDOStatement;
use YourOrm\Exception\QueryExecutionException;

/**
 * Represents a database connection.
 */
class Connection
{
    private ?PDO $pdo = null;

    /**
     * Connection constructor.
     *
     * @param string $host The database host.
     * @param string $username The database username.
     * @param string $password The database password.
     * @param string $dbName The database name.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password,
        private readonly string $dbName
    ) {
    }

    /**
     * Establishes a PDO connection.
     *
     * @return PDO The PDO instance.
     * @throws PDOException If the connection fails.
     */
    public function connect(): PDO
    {
        if ($this->pdo === null) {
            $dsn = "mysql:host={$this->host};dbname={$this->dbName}";
            try {
                $this->pdo = new PDO($dsn, $this->username, $this->password);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                // It's generally a good practice to log the error message.
                // For now, we'll just re-throw the exception.
                throw new PDOException("Connection failed: " . $e->getMessage());
            }
        }
        return $this->pdo;
    }

    /**
     * Closes the PDO connection.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Executes an SQL query with parameters.
     *
     * @param string $sql The SQL query to execute.
     * @param array<string, mixed> $params The parameters to bind to the query.
     * @return PDOStatement The PDOStatement object.
     * @throws PDOException If the query execution fails or the connection is not established.
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        if ($this->pdo === null) {
            throw new PDOException("Not connected to the database. Call connect() first.");
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new QueryExecutionException($e, $sql, $params);
        }
    }

    /**
     * Gets the ID of the last inserted row or sequence value.
     *
     * @param string|null $name Name of the sequence object from which the ID should be returned.
     * @return string|false The ID of the last inserted row, or false on failure.
     * @throws PDOException If not connected to the database.
     */
    public function getLastInsertId(?string $name = null): string|false
    {
        if ($this->pdo === null) {
            throw new PDOException("Not connected to the database.");
        }
        return $this->pdo->lastInsertId($name);
    }

    /**
     * Initiates a transaction.
     *
     * @throws PDOException If there is already a transaction started or if the driver does not support transactions.
     */
    public function beginTransaction(): void
    {
        $this->connect()->beginTransaction();
    }

    /**
     * Commits a transaction.
     *
     * @throws PDOException If there is no active transaction.
     */
    public function commit(): void
    {
        $this->connect()->commit();
    }

    /**
     * Rolls back a transaction.
     *
     * @throws PDOException If there is no active transaction.
     */
    public function rollBack(): void
    {
        $this->connect()->rollBack();
    }
}
