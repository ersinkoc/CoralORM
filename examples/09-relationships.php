<?php

require_once __DIR__ . '/bootstrap.php';

use YourVendor\PhpOrm\EntityManager;
// Assume these entities exist and are correctly mapped with relationships
// e.g., in a directory like src/Entity/
use YourVendor\PhpOrm\Entity\User;        // User entity
use YourVendor\PhpOrm\Entity\UserProfile; // For One-to-One with User
use YourVendor\PhpOrm\Entity\Order;       // For One-to-Many with User
use YourVendor\PhpOrm\Entity\Product;     // For Many-to-Many with Order (or User)
use YourVendor\PhpOrm\Entity\Tag;         // For Many-to-Many with Product

// Assuming $entityManager is already configured in bootstrap.php
/** @var EntityManager $entityManager */

echo "Relationship Examples\n";
echo "----------------------\n\n";

// --- One-to-One Relationship ---
// Example: User and UserProfile. Each User has one UserProfile, and each UserProfile belongs to one User.
// Assume User entity has a `userProfile` property mapped as OneToOne.
// Assume UserProfile entity has a `user` property mapped as OneToOne (inverse side).

echo "1. One-to-One Relationship (User and UserProfile):\n";

// Create a new User
$user1 = new User();
$user1->setName('Alice Wonderland');
$user1->setEmail('alice@example.com');
// Set other necessary fields for User, e.g., createdAt if required by your entity
if (method_exists($user1, 'setCreatedAt')) {
    $user1->setCreatedAt(new \DateTime());
}


// Create a new UserProfile
$userProfile1 = new UserProfile();
$userProfile1->setBio('Loves to explore.');
$userProfile1->setAvatarUrl('http://example.com/alice.png');
// $userProfile1->setRegistrationDate(new \DateTime()); // If UserProfile has such a field

// Link the UserProfile to the User
// In a bidirectional relationship, you typically set both sides,
// but the owning side is responsible for persistence.
// Let's assume User is the owning side or that setting one side updates the other via entity logic.
$user1->setUserProfile($userProfile1); // Assumes method setUserProfile exists in User entity
// $userProfile1->setUser($user1); // If you need to set the inverse side explicitly

try {
    $entityManager->persist($user1); // Persisting User should also persist UserProfile if cascade persist is configured
    $entityManager->persist($userProfile1); // Or persist explicitly if cascade is not set for this relationship
    $entityManager->flush();
    echo " - Created User 'Alice Wonderland' with her profile.\n";

    // Retrieve the user and access their profile
    $retrievedUser = $entityManager->getRepository(User::class)->find($user1->getId());
    if ($retrievedUser && $retrievedUser->getUserProfile()) {
        echo " - Retrieved Alice's bio: " . $retrievedUser->getUserProfile()->getBio() . "\n";
    } else {
        echo " - Could not retrieve Alice or her profile.\n";
    }
} catch (\Exception $e) {
    echo " - Error in One-to-One example: " . $e->getMessage() . "\n";
    echo "   Ensure User and UserProfile entities are defined with a OneToOne relationship.\n";
    echo "   User entity might need: private ?UserProfile \$userProfile; methods getUserProfile(), setUserProfile(). Annotations for ORM.\n";
    echo "   UserProfile entity might need: private ?User \$user; methods getUser(), setUser(). Annotations for ORM.\n";
}
echo "\n";


// --- One-to-Many Relationship ---
// Example: User and Order. One User can have many Orders.
// Assume User entity has an `orders` property (e.g., ArrayCollection) mapped as OneToMany.
// Assume Order entity has a `user` property mapped as ManyToOne (inverse side).

echo "2. One-to-Many Relationship (User and Orders):\n";

$user2 = new User();
$user2->setName('Bob The Builder');
$user2->setEmail('bob@example.com');
if (method_exists($user2, 'setCreatedAt')) {
    $user2->setCreatedAt(new \DateTime());
}

$order1 = new Order();
$order1->setAmount(100.50);
$order1->setOrderDate(new \DateTime('2023-10-26'));
// $order1->setUser($user2); // Set the owning side (ManyToOne)

$order2 = new Order();
$order2->setAmount(75.25);
$order2->setOrderDate(new \DateTime('2023-10-27'));
// $order2->setUser($user2); // Set the owning side

// Add orders to the user (if using a helper method in User entity)
// This depends on your entity implementation (e.g., if User has an addOrder method)
if (method_exists($user2, 'addOrder')) {
    $user2->addOrder($order1); // This method should also set $order1->setUser($user2)
    $user2->addOrder($order2);
} else {
    // If no addOrder helper, set the owning side (ManyToOne) on each Order
    $order1->setUser($user2);
    $order2->setUser($user2);
}


try {
    $entityManager->persist($user2);  // Persist user
    $entityManager->persist($order1); // Persist order1
    $entityManager->persist($order2); // Persist order2
    // If cascade persist is set on User::orders, only persisting $user2 might be enough.
    $entityManager->flush();
    echo " - Created User 'Bob The Builder' with two orders.\n";

    // Retrieve the user and access their orders
    $retrievedBob = $entityManager->getRepository(User::class)->find($user2->getId());
    if ($retrievedBob) {
        echo " - Bob's orders:\n";
        // Assuming getOrders() returns a collection that can be iterated
        if (method_exists($retrievedBob, 'getOrders') && count($retrievedBob->getOrders()) > 0) {
            foreach ($retrievedBob->getOrders() as $order) {
                echo "   - Order ID: " . $order->getId() . ", Amount: " . $order->getAmount() . "\n";
            }
        } else {
            echo "   - Bob has no orders or getOrders() method is missing/returning empty.\n";
        }
    } else {
        echo " - Could not retrieve Bob.\n";
    }
} catch (\Exception $e) {
    echo " - Error in One-to-Many example: " . $e->getMessage() . "\n";
    echo "   Ensure User has a OneToMany relationship with Order (e.g., 'orders' property).\n";
    echo "   Order entity should have a ManyToOne relationship with User (e.g., 'user' property).\n";
}
echo "\n";


// --- Many-to-Many Relationship ---
// Example: Product and Tag. A Product can have many Tags, and a Tag can be applied to many Products.
// This usually involves a join table (e.g., product_tag).
// Assume Product entity has a `tags` property (e.g., ArrayCollection) mapped as ManyToMany.
// Assume Tag entity has a `products` property (e.g., ArrayCollection) mapped as ManyToMany (inverse side).

echo "3. Many-to-Many Relationship (Product and Tag):\n";

// Create some Tags
$tag1 = new Tag();
$tag1->setName('Electronics');

$tag2 = new Tag();
$tag2->setName('Wearable');

$tag3 = new Tag();
$tag3->setName('Gadget');

try {
    $entityManager->persist($tag1);
    $entityManager->persist($tag2);
    $entityManager->persist($tag3);
    // $entityManager->flush(); // Flush here or later

    // Create Products
    $product1 = new Product();
    $product1->setName('Smartwatch Series X');
    $product1->setPrice(299.99);
    // Add tags to product1 (assuming Product has an addTag method)
    if (method_exists($product1, 'addTag')) {
        $product1->addTag($tag1); // Electronics
        $product1->addTag($tag2); // Wearable
        $product1->addTag($tag3); // Gadget
    } else {
        echo " - Product entity is missing addTag() method. Cannot demonstrate adding tags.\n";
    }


    $product2 = new Product();
    $product2->setName('Wireless Headphones Pro');
    $product2->setPrice(199.50);
    if (method_exists($product2, 'addTag')) {
        $product2->addTag($tag1); // Electronics
        $product2->addTag($tag3); // Gadget
    } else {
        echo " - Product entity is missing addTag() method. Cannot demonstrate adding tags.\n";
    }

    $entityManager->persist($product1);
    $entityManager->persist($product2);
    $entityManager->flush();
    echo " - Created Products with Tags.\n";

    // Retrieve a product and list its tags
    $retrievedProduct = $entityManager->getRepository(Product::class)->find($product1->getId());
    if ($retrievedProduct && method_exists($retrievedProduct, 'getTags')) {
        echo " - Tags for '" . $retrievedProduct->getName() . "':\n";
        foreach ($retrievedProduct->getTags() as $tag) {
            echo "   - " . $tag->getName() . "\n";
        }
    } else {
        echo " - Could not retrieve product or its tags. Ensure getTags() method exists.\n";
    }

    // Retrieve a tag and list products associated with it
    $retrievedTag = $entityManager->getRepository(Tag::class)->find($tag1->getId()); // Electronics tag
    if ($retrievedTag && method_exists($retrievedTag, 'getProducts')) {
        echo " - Products tagged with '" . $retrievedTag->getName() . "':\n";
        foreach ($retrievedTag->getProducts() as $product) {
            echo "   - " . $product->getName() . "\n";
        }
    } else {
        echo " - Could not retrieve tag or its products. Ensure getProducts() method exists.\n";
    }

} catch (\Exception $e) {
    echo " - Error in Many-to-Many example: " . $e->getMessage() . "\n";
    echo "   Ensure Product and Tag entities are defined with a ManyToMany relationship.\n";
    echo "   Product might need: private Collection \$tags; methods getTags(), addTag(), removeTag(). Annotations for ORM.\n";
    echo "   Tag might need: private Collection \$products; methods getProducts(), addProduct(), removeProduct(). Annotations for ORM.\n";
}
echo "\n";

echo "----------------------\n";
echo "Relationship examples complete.\n";
echo "For these examples to work, you need to have User, UserProfile, Order, Product, and Tag entities\n";
echo "defined with appropriate properties and ORM mapping annotations for relationships.\n";
echo "Also, ensure your database schema supports these relationships (e.g., foreign keys, join tables).\n";

?>
```
