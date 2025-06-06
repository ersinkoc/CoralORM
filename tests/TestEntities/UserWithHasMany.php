<?php

namespace Tests\YourOrm\TestEntities;

use YourOrm\Entity;
use YourOrm\Mapping\Table;
use YourOrm\Mapping\PrimaryKey;
use YourOrm\Mapping\Column;
use YourOrm\Mapping\HasMany;

#[Table('users')]
class UserWithHasMany extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?string $name = null;

    #[HasMany(relatedEntity: PostWithBelongsTo::class, foreignKey: 'user_id', localKey: 'id')]
    public $posts;
}
