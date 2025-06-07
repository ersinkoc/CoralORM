<?php

namespace Tests\CoralORM\TestEntities;

use CoralORM\Entity;
use CoralORM\Mapping\Table;
use CoralORM\Mapping\PrimaryKey;
use CoralORM\Mapping\Column;
use CoralORM\Mapping\BelongsTo;

#[Table('posts')]
class PostWithBelongsTo extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?string $title = null;

    #[Column(name: 'user_id')]
    public ?int $userId = null;

    #[BelongsTo(relatedEntity: UserWithHasMany::class, foreignKey: 'user_id', ownerKey: 'id')]
    public $user; // This definition might need adjustment if UserWithHasMany is not the only user entity.
                  // For the purpose of testing HasMany on UserWithHasMany, this should be fine.
                  // If UserWithHasManyDefaultLocalKey also had a BelongsTo from Post, it would need another property.
}
