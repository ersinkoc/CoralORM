<?php

require_once __DIR__ . '/bootstrap.php';

use YourVendor\PhpOrm\EntityManager;
use YourVendor\PhpOrm\Entity\User; // Assuming you have a User entity
use YourVendor\PhpOrm\Entity\Order; // Assuming you have an Order entity
use YourVendor\PhpOrm\Query\QueryBuilder;

// Assuming $entityManager is already configured in bootstrap.php
/** @var EntityManager $entityManager */

echo "Advanced Query Building Examples\n";
echo "---------------------------------\n\n";

// --- WHERE clauses with different operators ---

echo "1. WHERE clause with LIKE operator:\n";
$usersLike = $entityManager->getRepository(User::class)
    ->createQueryBuilder('u')
    ->where('u.name LIKE :name')
    ->setParameter('name', 'John%')
    ->getQuery()
    ->getResult();

foreach ($usersLike as $user) {
    echo " - User found (LIKE 'John%'): " . $user->getName() . "\n";
}
echo "\n";

echo "2. WHERE clause with IN operator:\n";
$usersIn = $entityManager->getRepository(User::class)
    ->createQueryBuilder('u')
    ->where('u.id IN (:ids)')
    ->setParameter('ids', [1, 2, 3]) // Assuming User IDs 1, 2, 3 exist
    ->getQuery()
    ->getResult();

foreach ($usersIn as $user) {
    echo " - User found (IN [1, 2, 3]): " . $user->getName() . " (ID: " . $user->getId() . ")\n";
}
echo "\n";

echo "3. WHERE clause with BETWEEN operator (assuming an 'age' or 'createdAt' field):\n";
// For this example, let's assume User entity has a 'createdAt' DateTime field
// and we want to find users created within a certain date range.
// Adjust field name and values as per your actual User entity.
try {
    $usersBetween = $entityManager->getRepository(User::class)
        ->createQueryBuilder('u')
        ->where('u.createdAt BETWEEN :startDate AND :endDate')
        ->setParameter('startDate', new \DateTime('2023-01-01'))
        ->setParameter('endDate', new \DateTime('2023-12-31'))
        ->getQuery()
        ->getResult();

    foreach ($usersBetween as $user) {
        echo " - User found (createdAt BETWEEN 2023-01-01 AND 2023-12-31): " . $user->getName() . " (Created: " . $user->getCreatedAt()->format('Y-m-d') . ")\n";
    }
} catch (\Exception $e) {
    echo " - Could not execute BETWEEN query. Ensure User entity has 'createdAt' field and it's mapped correctly. Error: " . $e->getMessage() . "\n";
}
echo "\n";


// --- JOINs (INNER, LEFT, RIGHT) ---
// Assuming User has a relationship with Order (e.g., a user can have multiple orders)

echo "4. INNER JOIN (Users with their Orders):\n";
// We need to define relationships in entities for JOINs to work seamlessly.
// Let's assume User entity has a one-to-many relationship with Order entity.
// And Order entity has a many-to-one relationship back to User.
// (This part would typically be defined in the entity classes themselves)

// Example: Find users who have placed orders, along with their order IDs.
// Adjust 'o.user' based on your actual entity relationship mapping.
try {
    $queryBuilder = $entityManager->getRepository(User::class)->createQueryBuilder('u');
    $usersWithOrders = $queryBuilder
        ->innerJoin('u.orders', 'o') // 'u.orders' assumes a mapped relationship in User entity
        ->select('u.name as userName, o.id as orderId, o.amount as orderAmount') // Assuming Order has an 'amount' field
        ->getQuery()
        ->getResult();

    if (count($usersWithOrders) > 0) {
        foreach ($usersWithOrders as $result) {
            echo " - User: " . $result['userName'] . ", Order ID: " . $result['orderId'] . ", Amount: " . $result['orderAmount'] . "\n";
        }
    } else {
        echo " - No users found with orders (INNER JOIN).\n";
    }
} catch (\Exception $e) {
    echo " - Could not execute INNER JOIN. Ensure entities and relationships are defined. Error: " . $e->getMessage() . "\n";
}
echo "\n";


echo "5. LEFT JOIN (All Users and their Orders, if any):\n";
// Example: List all users, and if they have orders, list their order IDs.
try {
    $queryBuilder = $entityManager->getRepository(User::class)->createQueryBuilder('u');
    $allUsersAndOrders = $queryBuilder
        ->leftJoin('u.orders', 'o') // 'u.orders' assumes a mapped relationship in User entity
        ->select('u.name as userName, o.id as orderId, o.amount as orderAmount')
        ->getQuery()
        ->getResult();

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
} catch (\Exception $e) {
    echo " - Could not execute LEFT JOIN. Ensure entities and relationships are defined. Error: " . $e->getMessage() . "\n";
}
echo "\n";

// RIGHT JOIN is less commonly used directly with ORM Query Builders starting from the 'left' side.
// Often, a RIGHT JOIN can be rewritten as a LEFT JOIN by switching the order of entities.
// However, if your ORM's QueryBuilder supports it directly, the syntax would be similar:
// ->rightJoin('u.orders', 'o')
// For this example, we'll focus on how it could be achieved by starting from Order entity if needed.
echo "6. RIGHT JOIN (Conceptually - All Orders and their Users):\n";
// This is like finding all orders and the user who placed them.
// If an order somehow existed without a user (not typical for good schema design),
// that order would still be listed.
// This is effectively a LEFT JOIN if you start query from Order entity.
try {
    $queryBuilder = $entityManager->getRepository(Order::class)->createQueryBuilder('o');
    $allOrdersAndUsers = $queryBuilder
        ->leftJoin('o.user', 'u') // 'o.user' assumes a mapped relationship in Order entity
        ->select('o.id as orderId, o.amount as orderAmount, u.name as userName')
        ->getQuery()
        ->getResult();

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
} catch (\Exception $e) {
    echo " - Could not execute conceptual RIGHT JOIN (as LEFT JOIN from Order). Ensure entities and relationships are defined. Error: " . $e->getMessage() . "\n";
}
echo "\n";


// --- ORDER BY and GROUP BY clauses ---

echo "7. ORDER BY clause:\n";
// Example: Find all users, ordered by their name in descending order.
$usersOrdered = $entityManager->getRepository(User::class)
    ->createQueryBuilder('u')
    ->orderBy('u.name', 'DESC')
    ->getQuery()
    ->getResult();

foreach ($usersOrdered as $user) {
    echo " - User (Ordered by Name DESC): " . $user->getName() . "\n";
}
echo "\n";

echo "8. GROUP BY clause (and aggregate functions):\n";
// Example: Count the number of orders per user.
// This requires a relationship set up (User hasMany Orders).
// The select statement needs to include the grouping field and the aggregate.
try {
    $queryBuilder = $entityManager->getRepository(Order::class)->createQueryBuilder('o');
    $ordersCountByUser = $queryBuilder
        ->innerJoin('o.user', 'u') // Join with User entity
        ->select('u.name as userName, COUNT(o.id) as orderCount')
        ->groupBy('u.id') // Group by user id (or user name if unique)
        ->orderBy('orderCount', 'DESC')
        ->getQuery()
        ->getResult(); // Returns an array of arrays/objects

    if (count($ordersCountByUser) > 0) {
        echo " - Orders per user:\n";
        foreach ($ordersCountByUser as $row) {
            echo "   - User: " . $row['userName'] . ", Order Count: " . $row['orderCount'] . "\n";
        }
    } else {
        echo " - No data found for GROUP BY example.\n";
    }
} catch (\Exception $e) {
    echo " - Could not execute GROUP BY query. Ensure entities, relationships (Order.user), and fields are correct. Error: " . $e->getMessage() . "\n";
}
echo "\n";

echo "9. Query with multiple conditions (WHERE AND/OR):\n";
// Example: Find users named 'John Doe' OR users with email ending in '@example.com'
// For an AND condition, you can chain multiple ->where() or ->andWhere() calls.
// For an OR condition, you use ->orWhere().
$usersComplexCondition = $entityManager->getRepository(User::class)
    ->createQueryBuilder('u')
    ->where('u.name = :name')
    ->orWhere('u.email LIKE :emailPattern')
    ->setParameter('name', 'Jane Doe') // Assuming a 'Jane Doe' might exist
    ->setParameter('emailPattern', '%@example.com')
    ->orderBy('u.id', 'ASC')
    ->getQuery()
    ->getResult();

echo " - Users matching 'Jane Doe' OR email ending with '@example.com':\n";
if (count($usersComplexCondition) > 0) {
    foreach ($usersComplexCondition as $user) {
        echo "   - User: " . $user->getName() . " (Email: " . $user->getEmail() . ")\n";
    }
} else {
    echo "   - No users found matching the complex criteria.\n";
}
echo "\n";


echo "---------------------------------\n";
echo "Advanced query examples complete.\n";
echo "Please ensure you have appropriate entities (User, Order), relationships, \n";
echo "and sample data in your database for these queries to yield results.\n";
echo "The User entity should have at least 'id', 'name', 'email', 'createdAt' (DateTime). \n";
echo "The Order entity should have at least 'id', 'amount', and a reference to User ('user').\n";

?>
```
