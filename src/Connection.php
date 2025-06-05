<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use PDOStatement;

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
            // Log error and re-throw
            throw new PDOException("Query execution failed: " . $e->getMessage());
        }
    }
}
