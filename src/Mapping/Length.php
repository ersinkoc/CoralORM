<?php

declare(strict_types=1);

namespace CoralORM\Mapping;

use Attribute;
use InvalidArgumentException;

/**
 * Annotation to specify length constraints for a string property.
 * The ORM should enforce this before persisting or updating an entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Length
{
    public ?int $min;
    public ?int $max;
    public ?string $message;

    public function __construct(?int $min = null, ?int $max = null, ?string $message = null)
    {
        if ($min === null && $max === null) {
            throw new InvalidArgumentException('Either min or max length must be specified for Length annotation.');
        }

        if ($min !== null && $min < 0) {
            throw new InvalidArgumentException('Minimum length cannot be negative.');
        }

        if ($max !== null && $max < 0) {
            throw new InvalidArgumentException('Maximum length cannot be negative.');
        }

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException('Minimum length cannot be greater than maximum length.');
        }

        $this->min = $min;
        $this->max = $max;
        $this->message = $message;
    }
}
