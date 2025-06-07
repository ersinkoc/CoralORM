<?php

namespace Tests\CoralORM\TestEntities;

use CoralORM\Entity;
use CoralORM\Mapping\Table;
use CoralORM\Mapping\PrimaryKey;
use CoralORM\Mapping\Column;
use CoralORM\Mapping\HasMany;

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
