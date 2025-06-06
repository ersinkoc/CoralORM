<?php

namespace Tests\YourOrm\TestEntities;

use YourOrm\Entity;
use YourOrm\Mapping\Table;
use YourOrm\Mapping\PrimaryKey;
use YourOrm\Mapping\Column;
use YourOrm\Mapping\HasMany;

#[Table('users_default_key')]
class UserWithHasManyDefaultLocalKey extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(name: 'custom_id')] // Use a non-default PK column name
    public ?int $id = null; // Property name is 'id', but column name is 'custom_id'

    #[Column]
    public ?string $name = null;

    // 'user_id' is the column on PostWithBelongsTo table linking back to this user's PK ('custom_id')
    #[HasMany(relatedEntity: PostWithBelongsTo::class, foreignKey: 'user_id')]
    public $postsDefaultKey;
}
