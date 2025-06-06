<?php

declare(strict_types=1);

namespace YourOrm\Exception;

use PDOException;
use Throwable; // Import Throwable for previous exception type hint

class QueryExecutionException extends PDOException
{
    public string $sqlQuery;
    public array $parameters;

    /**
     * QueryExecutionException constructor.
     *
     * @param PDOException $previous The original PDOException.
     * @param string $sqlQuery The SQL query that failed.
     * @param array $parameters The parameters passed to the query.
     */
    public function __construct(PDOException $previous, string $sqlQuery, array $parameters)
    {
        $this->sqlQuery = $sqlQuery;
        $this->parameters = $parameters;

        $message = "Query execution failed: " . $previous->getMessage() .
                   "\nSQL: " . $sqlQuery .
                   "\nParams: " . json_encode($parameters);

        // PDOException's constructor signature is:
        // __construct (string $message = "", int $code = 0 , Throwable $previous = null)
        // The 'code' for PDOException is often the SQLSTATE error code, which is a string.
        // However, the parent constructor expects an int.
        // $previous->getCode() can return a string (SQLSTATE) or an int (driver-specific error code).
        // We should try to preserve the original error code if it's an int, or use 0.
        // $previous->errorInfo[1] often contains the driver-specific error code as int.
        $code = 0; // Default code
        if (isset($previous->errorInfo[1]) && is_int($previous->errorInfo[1])) {
            $code = $previous->errorInfo[1];
        } elseif (is_int($previous->getCode())) {
            $code = $previous->getCode();
        }

        parent::__construct($message, $code, $previous);
    }
}
