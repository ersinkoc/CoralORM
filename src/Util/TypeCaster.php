<?php

declare(strict_types=1);

namespace CoralORM\Util;

use DateTimeImmutable;
use DateTimeInterface;
use Exception; // For handling invalid date strings or JSON

class TypeCaster
{
    /**
     * Casts a value to a specified PHP type.
     *
     * @param mixed $value The value to cast.
     * @param ?string $type The target PHP type (e.g., 'int', 'string', 'bool', 'float', 'DateTimeImmutable', 'array').
     * @return mixed The casted value.
     */
    public static function castToPhpType(mixed $value, ?string $type): mixed
    {
        if ($value === null || $type === null) {
            return $value;
        }

        switch ($type) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            case 'float':
            case 'double':
                return (float) $value;
            case 'DateTimeImmutable':
                if ($value instanceof DateTimeImmutable) {
                    return $value;
                }
                if (is_string($value)) {
                    try {
                        return new DateTimeImmutable($value);
                    } catch (Exception $e) {
                        // Optionally log error or handle specific date formats
                        return null; // Or throw custom exception
                    }
                }
                if (is_int($value)) { // Assume UNIX timestamp
                     return (new DateTimeImmutable())->setTimestamp($value);
                }
                return null; // Cannot cast
            case 'array':
                if (is_string($value)) {
                    // Try to decode if it's a JSON string
                    $decoded = json_decode($value, true);
                    return json_last_error() === JSON_ERROR_NONE ? $decoded : null; // Or original string if not JSON
                }
                return is_array($value) ? $value : null; // Or (array)$value for basic casting
            default:
                // For unknown types or custom object types (not handled here without FQCN)
                return $value;
        }
    }

    /**
     * Casts a PHP value to a database-compatible format.
     *
     * @param mixed $value The PHP value.
     * @param ?string $phpType The original PHP type (can inform casting, e.g., if $value is object).
     * @return mixed The database-compatible value (usually scalar or null).
     */
    public static function castToDatabase(mixed $value, ?string $phpType = null): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s'); // Common database timestamp format
        }

        if (is_array($value) || ($phpType === 'array' && is_object($value))) {
             // For objects that should be cast to array then JSON
            if(is_object($value) && method_exists($value, 'toArray')) {
                return json_encode($value->toArray());
            }
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 1 : 0; // Common boolean representation in DB
        }

        // Other types (int, float, string) are usually fine as is for PDO.
        return $value;
    }
}
