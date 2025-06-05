<?php
declare(strict_types=1);
namespace YourOrm\Mapping;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class PrimaryKey
{
    public function __construct(public bool $autoIncrement = true) {}
}
