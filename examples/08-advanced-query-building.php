<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php'; // Corrected bootstrap path

namespace Examples\AdvQuery; // Using a distinct namespace for these example entities

use CoralORM\Connection;
use CoralORM\Entity;
use CoralORM\Repository;
use CoralORM\QueryBuilder;
use CoralORM\Mapping\Table;
use CoralORM\Mapping\Column;
use CoralORM\Mapping\PrimaryKey;
use CoralORM\Mapping\HasMany;
use CoralORM\Mapping\BelongsTo;
use CoralORM\Mapping\CreatedAt;
use DateTimeImmutable;

echo PHP_EOL . "--- Example 08: Advanced Query Building with CoralORM ---" . PHP_EOL;

// --- Entity Definitions ---

#[Table(name: 'users')]
class User extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'name', type: 'string')]
    public ?string $name = null;

    #[Column(name: 'email', type: 'string')]
    public ?string $email = null;

    #[CreatedAt]
    #[Column(name: 'created_at', type: 'DateTimeImmutable')]
    public ?DateTimeImmutable $created_at = null;

    // Relationship: User has many Orders
    // This property will be populated by the Repository's 'with' method if eager loading.
    // QueryBuilder itself doesn't automatically use this for joins; joins are manual.
    #[HasMany(relatedEntity: Order::class, foreignKey: 'user_id', localKey: 'id')]
    public array $orders = []; // Initialized to empty array

    // Getter methods (optional, Entity uses magic __get)
    public function getName(): ?string { return $this->name; }
    public function getEmail(): ?string { return $this->email; }
    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): ?DateTimeImmutable { return $this->created_at; }
}

#[Table(name: 'orders')]
class Order extends Entity
{
    #[PrimaryKey(autoIncrement: true)]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'user_id', type: 'int')]
    public ?int $user_id = null;

    #[Column(name: 'amount', type: 'float')]
    public ?float $amount = null;

    #[CreatedAt]
    #[Column(name: 'created_at', type: 'DateTimeImmutable')]
    public ?DateTimeImmutable $created_at = null;

    // Relationship: Order belongs to a User
    #[BelongsTo(relatedEntity: User::class, foreignKey: 'user_id', ownerKey: 'id')]
    public ?User $user = null; // Initialized to null

    public function getId(): ?int { return $this->id; }
    public function getAmount(): ?float { return $this->amount; }
    public function getUserId(): ?int { return $this->user_id; }
}

// --- End of Entity Definitions ---


$connection = get_db_connection();

if (!$connection) {
    echo "Failed to get database connection. Please check config.php and bootstrap.php." . PHP_EOL;
    exit(1);
}

// Note: CoralORM\Repository is simpler and does not have createQueryBuilder method.
// We will use CoralORM\QueryBuilder directly.

try {
    $userRepository = new Repository($connection, User::class); // For potential direct repo use
    $orderRepository = new Repository($connection, Order::class); // For potential direct repo use

    echo "\n1. WHERE clause with LIKE operator:\n";
    $qb1 = new QueryBuilder($connection);
    $usersLike = $qb1->select('id', 'name', 'email')
        ->from('users')
        ->where('name', 'LIKE', 'John%') // Assuming 'John%' exists from previous examples or schema
        ->fetchAll();

    foreach ($usersLike as $user) {
        echo " - User found (LIKE 'John%'): " . $user['name'] . "\n";
    }
    echo "\n";

    echo "2. WHERE clause with IN operator:\n";
    $qb2 = new QueryBuilder($connection);
    // Ensure users with ID 1, 2, 3 exist or adjust array.
    // Previous examples might create user ID 1 (johndoe_example) and 2 (jane_doe_example)
    $userIDsToFind = [1, 2, 3];
    $usersIn = $qb2->select('id', 'name', 'email')
        ->from('users')
        ->whereIn('id', $userIDsToFind) // QueryBuilder has whereIn
        ->fetchAll();

    foreach ($usersIn as $user) {
        echo " - User found (IN [" . implode(', ', $userIDsToFind) . "]): " . $user['name'] . " (ID: " . $user['id'] . ")\n";
    }
    echo "\n";

    echo "3. WHERE clause with BETWEEN operator (using 'created_at' field):\n";
    $qb3 = new QueryBuilder($connection);
    $startDate = (new DateTimeImmutable('2023-01-01'))->format('Y-m-d H:i:s');
    $endDate = (new DateTimeImmutable('2023-12-31'))->format('Y-m-d H:i:s');
    // Note: QueryBuilder does not have a specific ->between() method.
    // We construct it using two WHERE clauses.
    $usersBetween = $qb3->select('id', 'name', 'created_at')
        ->from('users')
        ->where('created_at', '>=', $startDate)
        ->where('created_at', '<=', $endDate) // Additional where implies AND
        ->fetchAll();

    foreach ($usersBetween as $user) {
        echo " - User found (created_at BETWEEN {$startDate} AND {$endDate}): " . $user['name'] . " (Created: " . $user['created_at'] . ")\n";
    }
    echo "\n";

    echo "4. INNER JOIN (Users with their Orders):\n";
    $qb4 = new QueryBuilder($connection);
    // Manual JOIN condition
    $usersWithOrders = $qb4->select('u.name as userName', 'o.id as orderId', 'o.amount as orderAmount')
        ->from('users', 'u')
        ->join('orders AS o', 'u.id = o.user_id', 'INNER')
        ->fetchAll();

    if (count($usersWithOrders) > 0) {
        foreach ($usersWithOrders as $result) {
            echo " - User: " . $result['userName'] . ", Order ID: " . $result['orderId'] . ", Amount: " . $result['orderAmount'] . "\n";
        }
    } else {
        echo " - No users found with orders (INNER JOIN).\n";
    }
    echo "\n";

    echo "5. LEFT JOIN (All Users and their Orders, if any):\n";
    $qb5 = new QueryBuilder($connection);
    $allUsersAndOrders = $qb5->select('u.name as userName', 'o.id as orderId', 'o.amount as orderAmount')
        ->from('users', 'u')
        ->leftJoin('orders AS o', 'u.id = o.user_id')
        ->fetchAll();

    if (count($allUsersAndOrders) > 0) {
        foreach ($allUsersAndOrders as $result) {
            if ($result['orderId']) {
                echo " - User: " . $result['userName'] . ", Order ID: " . $result['orderId'] . ", Amount: " . $result['orderAmount'] . "\n";
            } else {
                echo " - User: " . $result['userName'] . ", No orders\n";
            }
        }
    } else {
        echo " - No users found (LEFT JOIN).\n";
    }
    echo "\n";


    echo "6. RIGHT JOIN (Conceptually - All Orders and their Users):\n";
    $qb6 = new QueryBuilder($connection);
    // This is effectively a LEFT JOIN if you start query from Order entity.
    $allOrdersAndUsers = $qb6->select('o.id as orderId', 'o.amount as orderAmount', 'u.name as userName')
        ->from('orders', 'o')
        ->leftJoin('users AS u', 'o.user_id = u.id') // Switched join condition
        ->fetchAll();

    if (count($allOrdersAndUsers) > 0) {
        foreach ($allOrdersAndUsers as $result) {
            if ($result['userName']) {
                echo " - Order ID: " . $result['orderId'] . ", Amount: " . $result['orderAmount'] . ", User: " . $result['userName'] . "\n";
            } else {
                echo " - Order ID: " . $result['orderId'] . ", Amount: " . $result['orderAmount'] . ", User: (No associated user)\n";
            }
        }
    } else {
        echo " - No orders found.\n";
    }
    echo "\n";


    echo "7. ORDER BY clause:\n";
    $qb7 = new QueryBuilder($connection);
    $usersOrdered = $qb7->select('id', 'name')
        ->from('users')
        ->orderBy('name', 'DESC')
        ->fetchAll();

    foreach ($usersOrdered as $user) {
        echo " - User (Ordered by Name DESC): " . $user['name'] . "\n";
    }
    echo "\n";

    echo "8. GROUP BY clause (and aggregate functions):\n";
    $qb8 = new QueryBuilder($connection);
    $ordersCountByUser = $qb8->select('u.name as userName', 'COUNT(o.id) as orderCount')
        ->from('users', 'u') // Start from users
        ->leftJoin('orders AS o', 'u.id = o.user_id') // Left join to include users with no orders
        ->groupBy('u.id, u.name') // Group by user id and name
        ->orderBy('orderCount', 'DESC')
        ->fetchAll();

    if (count($ordersCountByUser) > 0) {
        echo " - Orders per user:\n";
        foreach ($ordersCountByUser as $row) {
            echo "   - User: " . $row['userName'] . ", Order Count: " . $row['orderCount'] . "\n";
        }
    } else {
        echo " - No data found for GROUP BY example.\n";
    }
    echo "\n";

    echo "9. Query with multiple conditions (WHERE AND/OR):\n";
    $qb9 = new QueryBuilder($connection);
    // Example: Find users named 'Jane Doe' OR users with email ending in '@example.com'
    // CoralORM\QueryBuilder chains WHERE clauses with AND by default.
    // For OR, you need to construct the condition string manually or use a more advanced QueryBuilder.
    // The current QueryBuilder might not support complex OR conditions directly in a fluent way
    // without raw SQL in where().
    // For this example, let's assume we search for 'jane_doe_example' OR 'johndoe_example'
    // This specific ORM's QueryBuilder's where() method takes one condition.
    // A common way to do OR is `WHERE (condition1) OR (condition2)`.
    // The current QB might require raw SQL for complex ORs or a refactor.
    // Let's try with whereIn for a simple OR on the same column:
    $namesToFind = ['jane_doe_example', 'johndoe_example']; // From example 03
    $usersComplexCondition = $qb9->select('id', 'name', 'email')
        ->from('users')
        ->whereIn('name', $namesToFind)
        ->orderBy('id', 'ASC')
        ->fetchAll();

    echo " - Users matching names " . implode(' OR ', $namesToFind) . ":\n";
    if (count($usersComplexCondition) > 0) {
        foreach ($usersComplexCondition as $user) {
            echo "   - User: " . $user['name'] . " (Email: " . $user['email'] . ")\n";
        }
    } else {
        echo "   - No users found matching the complex criteria.\n";
    }
    echo "\n";


} catch (\PDOException $e) {
    echo "A PDOException occurred: " . $e->getMessage() . PHP_EOL;
} catch (\LogicException $e) {
    echo "A LogicException occurred: " . $e->getMessage() . PHP_EOL;
} catch (\Exception $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . PHP_EOL;
}


echo "---------------------------------\n";
echo "Advanced query examples complete with CoralORM.\n";
echo "Please ensure you have appropriate tables (users, orders), and sample data.\n";
echo "The 'users' table needs 'id', 'name', 'email', 'created_at'.\n";
echo "The 'orders' table needs 'id', 'user_id', 'amount', 'created_at'.\n";

?>
