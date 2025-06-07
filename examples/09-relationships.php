<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

namespace Examples\Relationships;

use CoralORM\Entity;
use CoralORM\Repository;
use CoralORM\Connection; // Only if directly used, Repository handles connection
use CoralORM\Mapping\Table;
use CoralORM\Mapping\Column;
use CoralORM\Mapping\PrimaryKey;
use CoralORM\Mapping\BelongsTo;
use CoralORM\Mapping\HasOne;
use CoralORM\Mapping\HasMany;
use CoralORM\Mapping\ManyToMany;
use CoralORM\Mapping\CreatedAt;
use CoralORM\Mapping\UpdatedAt;
use CoralORM\QueryBuilder; // Added for direct instantiation
use DateTimeImmutable;

echo PHP_EOL . "--- Example 09: Relationships with CoralORM ---" . PHP_EOL;

// --- Entity Definitions ---

// Forward declaration for circular dependencies if any (not strictly needed here with separate class defs)
class User {}
class UserProfile {}
class Order {}
class Product {}
class Tag {}


#[Table(name: 'users')]
class User extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public ?string $name = null;

    #[Column(type: 'string', unique: true)]
    public ?string $email = null;

    #[CreatedAt]
    #[Column(type: 'DateTimeImmutable')]
    public ?DateTimeImmutable $created_at = null;

    #[HasOne(relatedEntity: UserProfile::class, foreignKey: 'user_id')]
    public ?UserProfile $userProfile = null; // Property to hold related UserProfile

    #[HasMany(relatedEntity: Order::class, foreignKey: 'user_id')]
    public array $orders = []; // Property to hold related Orders
}

#[Table(name: 'user_profiles')]
class UserProfile extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public ?int $id = null;

    #[Column(type: 'int', unique: true)] // Assuming one profile per user
    public ?int $user_id = null;

    #[Column(type: 'string')]
    public ?string $bio = null;

    #[Column(name:'avatar_url', type: 'string', nullable: true)]
    public ?string $avatarUrl = null;

    #[BelongsTo(relatedEntity: User::class, foreignKey: 'user_id')]
    public ?User $user = null; // Property to hold related User
}

#[Table(name: 'orders')]
class Order extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public ?int $id = null;

    #[Column(type: 'int')]
    public ?int $user_id = null;

    #[Column(type: 'float')]
    public ?float $amount = null;

    #[Column(name: 'order_date', type: 'DateTimeImmutable')]
    public ?DateTimeImmutable $orderDate = null;

    #[BelongsTo(relatedEntity: User::class, foreignKey: 'user_id')]
    public ?User $user = null;
}

#[Table(name: 'products')]
class Product extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public ?string $name = null;

    #[Column(type: 'float')]
    public ?float $price = null;

    #[ManyToMany(
        relatedEntity: Tag::class,
        joinTableName: 'product_tag',
        manyToManyLocalKey: 'product_id', // Column in product_tag for Product's ID
        manyToManyForeignKey: 'tag_id'    // Column in product_tag for Tag's ID
    )]
    public array $tags = [];
}

#[Table(name: 'tags')]
class Tag extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(type: 'int')]
    public ?int $id = null;

    #[Column(type: 'string', unique: true)]
    public ?string $name = null;

    #[ManyToMany(
        relatedEntity: Product::class,
        joinTableName: 'product_tag',
        manyToManyLocalKey: 'tag_id',      // Column in product_tag for Tag's ID
        manyToManyForeignKey: 'product_id' // Column in product_tag for Product's ID
    )]
    public array $products = [];
}

// --- End of Entity Definitions ---

$connection = get_db_connection();
if (!$connection) {
    echo "Failed to get database connection." . PHP_EOL;
    exit(1);
}

$userRepository = new Repository($connection, User::class);
$userProfileRepository = new Repository($connection, UserProfile::class);
$orderRepository = new Repository($connection, Order::class);
$productRepository = new Repository($connection, Product::class);
$tagRepository = new Repository($connection, Tag::class);

try {
    echo "\n1. One-to-One Relationship (User and UserProfile):\n";
    $user1 = new User();
    $user1->name = 'Alice Wonderland';
    $user1->email = 'alice.' . time() . '@example.com'; // Unique email
    $userRepository->save($user1); // Save user to get ID

    if ($user1->id) {
        $userProfile1 = new UserProfile();
        $userProfile1->user_id = $user1->id; // Set the foreign key
        $userProfile1->bio = 'Loves to explore.';
        $userProfile1->avatarUrl = 'http://example.com/alice.png';
        $userProfileRepository->save($userProfile1);
        echo " - Created User '{$user1->name}' (ID: {$user1->id}) with her profile (ID: {$userProfile1->id}).\n";

        // Retrieve user and eager load profile
        $retrievedUser1 = $userRepository->with('userProfile')->find($user1->id);
        if ($retrievedUser1 && $retrievedUser1->userProfile instanceof UserProfile) {
            echo " - Retrieved Alice's bio via eager loading: " . $retrievedUser1->userProfile->bio . "\n";
        } else {
             echo " - Could not retrieve Alice's profile via eager loading. Check 'with()' implementation or relation setup.\n";
        }
    } else {
        echo " - Failed to save User1 for One-to-One example.\n";
    }
    echo "\n";


    echo "2. One-to-Many / Many-to-One (User and Orders):\n";
    $user2 = new User();
    $user2->name = 'Bob The Builder';
    $user2->email = 'bob.' . time() . '@example.com';
    $userRepository->save($user2);

    if ($user2->id) {
        $order1 = new Order();
        $order1->user_id = $user2->id; // Set FK
        $order1->amount = 100.50;
        $order1->orderDate = new DateTimeImmutable('2023-10-26');
        $orderRepository->save($order1);

        $order2 = new Order();
        $order2->user_id = $user2->id; // Set FK
        $order2->amount = 75.25;
        $order2->orderDate = new DateTimeImmutable('2023-10-27');
        $orderRepository->save($order2);
        echo " - Created User '{$user2->name}' (ID: {$user2->id}) with two orders (IDs: {$order1->id}, {$order2->id}).\n";

        // Retrieve user and eager load orders
        $retrievedBob = $userRepository->with('orders')->find($user2->id);
        if ($retrievedBob && !empty($retrievedBob->orders)) {
            echo " - Bob's orders (via eager loading):\n";
            foreach ($retrievedBob->orders as $order) {
                if ($order instanceof Order) { // Ensure it's an Order object
                    echo "   - Order ID: {$order->id}, Amount: {$order->amount}\n";
                }
            }
        } else {
            echo " - Bob has no orders or eager loading failed.\n";
        }
    } else {
        echo " - Failed to save User2 for One-to-Many example.\n";
    }
    echo "\n";


    echo "3. Many-to-Many Relationship (Product and Tag):\n";
    $tagElec = new Tag(); $tagElec->name = 'Electronics'; $tagRepository->save($tagElec);
    $tagWear = new Tag(); $tagWear->name = 'Wearable';    $tagRepository->save($tagWear);
    $tagGadg = new Tag(); $tagGadg->name = 'Gadget';      $tagRepository->save($tagGadg);

    if (!$tagElec->id || !$tagWear->id || !$tagGadg->id) {
        echo " - Failed to create tags for Many-to-Many example. Exiting M2M part.\n";
    } else {
        $product1 = new Product();
        $product1->name = 'Smartwatch Series X';
        $product1->price = 299.99;
        $productRepository->save($product1); // Save product to get ID

        $product2 = new Product();
        $product2->name = 'Wireless Headphones Pro';
        $product2->price = 199.50;
        $productRepository->save($product2);

        if (!$product1->id || !$product2->id) {
             echo " - Failed to create products for Many-to-Many example. Exiting M2M part.\n";
        } else {
            echo " - Created Tags (IDs: {$tagElec->id}, {$tagWear->id}, {$tagGadg->id}) and Products (IDs: {$product1->id}, {$product2->id}).\n";
            // To link them for ManyToMany, we need to interact with the join table.
            // CoralORM\Repository doesn't have direct methods for ManyToMany link/unlink.
            // This typically requires raw SQL via QueryBuilder or a dedicated service.
            // Let's simulate by adding records to 'product_tag' table if schema permits.
            // This is a simplification. A real ORM would handle this more gracefully.

            $qb = new QueryBuilder($connection); // Use CoralORM\QueryBuilder (added use statement)
            try {
                // Link product1 with all three tags
                $qb->insert('product_tag', ['product_id' => $product1->id, 'tag_id' => $tagElec->id]);
                $qb->insert('product_tag', ['product_id' => $product1->id, 'tag_id' => $tagWear->id]);
                $qb->insert('product_tag', ['product_id' => $product1->id, 'tag_id' => $tagGadg->id]);

                // Link product2 with Electronics and Gadget
                $qb->insert('product_tag', ['product_id' => $product2->id, 'tag_id' => $tagElec->id]);
                $qb->insert('product_tag', ['product_id' => $product2->id, 'tag_id' => $tagGadg->id]);
                echo " - Manually linked products and tags via QueryBuilder inserts to join table.\n";
            } catch (\Exception $e) {
                echo " - Error manually inserting into join table: " . $e->getMessage() . "\n";
                echo "   Ensure 'product_tag' table exists with 'product_id' and 'tag_id' columns.\n";
            }

            // Retrieve a product and its tags (requires Repository::with() to support ManyToMany)
            $retrievedProduct1 = $productRepository->with('tags')->find($product1->id);
            if ($retrievedProduct1 && !empty($retrievedProduct1->tags)) {
                echo " - Tags for '{$retrievedProduct1->name}' (via eager loading):\n";
                foreach ($retrievedProduct1->tags as $tag) {
                     if ($tag instanceof Tag) {
                        echo "   - {$tag->name}\n";
                     }
                }
            } else {
                echo " - Could not retrieve tags for '{$retrievedProduct1->name}' or eager loading for M2M failed.\n";
                echo "   (Note: Repository::with() for ManyToMany is complex and might need specific implementation)\n";
            }

            // Retrieve a tag and its products
            $retrievedTagElec = $tagRepository->with('products')->find($tagElec->id);
            if ($retrievedTagElec && !empty($retrievedTagElec->products)) {
                echo " - Products tagged with '{$retrievedTagElec->name}' (via eager loading):\n";
                foreach ($retrievedTagElec->products as $product) {
                    if ($product instanceof Product) {
                        echo "   - {$product->name}\n";
                    }
                }
            } else {
                echo " - Could not retrieve products for tag '{$retrievedTagElec->name}' or eager loading for M2M failed.\n";
            }
        }
    }

} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . ". Trace: " . $e->getTraceAsString() . PHP_EOL;
}

echo "\n----------------------\n";
echo "Relationship examples complete.\n";
echo "Ensure your database schema (schema.sql) includes: \n";
echo "  'users' (id, name, email, created_at)\n";
echo "  'user_profiles' (id, user_id FK to users, bio, avatar_url)\n";
echo "  'orders' (id, user_id FK to users, amount, order_date)\n";
echo "  'products' (id, name, price)\n";
echo "  'tags' (id, name)\n";
echo "  'product_tag' (product_id FK to products, tag_id FK to tags, PRIMARY KEY(product_id, tag_id))\n";

?>
