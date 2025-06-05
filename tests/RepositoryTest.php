<?php

declare(strict_types=1);

namespace Tests\YourOrm;

use YourOrm\Connection;
use YourOrm\Entity;
// QueryBuilder is used by Repository, not directly in most Repository tests if Connection is mocked
// use YourOrm\QueryBuilder;
use YourOrm\Repository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PDOStatement;
use YourOrm\Mapping\Table;
use YourOrm\Mapping\Column;
use YourOrm\Mapping\PrimaryKey;
use YourOrm\Mapping\CreatedAt;
use YourOrm\Mapping\UpdatedAt;
use YourOrm\Mapping\BelongsTo; // For relationship tests
use DateTimeImmutable;


#[Table(name: 'test_repo_entities')]
class TestAttributeRepositoryEntity extends Entity
{
    #[PrimaryKey]
    #[Column(name: 'repo_entity_id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'entity_name', type: 'string')]
    public ?string $name = null;

    #[Column(type: 'string')] // db name will be 'email_address' (snake_case default)
    public ?string $emailAddress = null;

    #[CreatedAt] // DB column name will be 'created_at' (snake_case default)
    #[Column(type: 'DateTimeImmutable')]
    public ?DateTimeImmutable $createdAt = null;

    #[UpdatedAt] // DB column name will be 'updated_at' (snake_case default)
    #[Column(type: 'DateTimeImmutable')]
    public ?DateTimeImmutable $updatedAt = null;

    // For BelongsTo relationship testing
    #[Column(name: 'related_item_fk_id', type: 'int')] // The FK column itself on test_repo_entities table
    public ?int $relatedItemFkId = null;

    // This property will hold the RelatedEntityForRepoTest object
    #[BelongsTo(relatedEntity: RelatedEntityForRepoTest::class, foreignKey: 'relatedItemFkId')] // FK prop name
    public ?RelatedEntityForRepoTest $relatedItem = null;
}

// Dummy related entity for testing BelongsTo
#[Table(name: 'related_items_for_repo')]
class RelatedEntityForRepoTest extends Entity
{
    #[PrimaryKey]
    #[Column(type: 'int')] // Default PK 'id' column
    public ?int $id = null;

    #[Column(name: 'related_name', type: 'string')]
    public ?string $relatedName = null;
}


class RepositoryTest extends TestCase
{
    private MockObject|Connection $connectionMock;
    private Repository $repository;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(Connection::class);
        // Pass FQCN of the test entity
        $this->repository = new Repository($this->connectionMock, TestAttributeRepositoryEntity::class);
    }

    public function testFind()
    {
        $pdoStmtMock = $this->createMock(PDOStatement::class);

        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                // Uses metadata: table 'test_repo_entities', pk 'repo_entity_id'
                "SELECT * FROM test_repo_entities WHERE repo_entity_id = :param0 LIMIT 1",
                [':param0' => 1] // Assuming ID 1 is passed as int
            )
            ->willReturn($pdoStmtMock);

        $pdoStmtMock->expects($this->once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
             // DB returns column names as defined in attributes
            ->willReturn([
                'repo_entity_id' => 1,
                'entity_name' => 'Test User From DB',
                'email_address' => 'test@example.com',
                'created_at' => '2023-01-01 10:00:00',
                'updated_at' => '2023-01-01 11:00:00',
                'related_item_fk_id' => 100 // FK value from DB
            ]);

        $entity = $this->repository->find(1);
        $this->assertInstanceOf(TestAttributeRepositoryEntity::class, $entity);
        $this->assertNotNull($entity, "Entity should be found.");
        if($entity) { // for static analysis
            $this->assertEquals(1, $entity->id); // Entity property 'id'
            $this->assertEquals('Test User From DB', $entity->name); // Entity property 'name'
            $this->assertEquals('test@example.com', $entity->emailAddress);
            $this->assertInstanceOf(DateTimeImmutable::class, $entity->createdAt);
            $this->assertInstanceOf(DateTimeImmutable::class, $entity->updatedAt);
            $this->assertEquals(100, $entity->relatedItemFkId); // FK property
        }
    }

    public function testFindReturnsNullIfEntityNotFound()
    {
        $pdoStmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            // SQL uses metadata: table 'test_repo_entities', pk 'repo_entity_id'
            ->with("SELECT * FROM test_repo_entities WHERE repo_entity_id = :param0 LIMIT 1", [':param0' => 'nonexistent'])
            ->willReturn($pdoStmtMock);
        $pdoStmtMock->expects($this->once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn(false);

        $this->assertNull($this->repository->find('nonexistent'));
    }

    public function testFindAll()
    {
        $rowData = [
            // DB returns column names
            ['repo_entity_id' => 1, 'entity_name' => 'User One', 'email_address' => 'one@example.com'],
            ['repo_entity_id' => 2, 'entity_name' => 'User Two', 'email_address' => 'two@example.com'],
        ];
        $pdoStmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            // SQL uses metadata: table 'test_repo_entities'
            ->with("SELECT * FROM test_repo_entities", [])
            ->willReturn($pdoStmtMock);
        $pdoStmtMock->expects($this->once())->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn($rowData);

        $entities = $this->repository->findAll();
        $this->assertCount(2, $entities);
        $this->assertInstanceOf(TestAttributeRepositoryEntity::class, $entities[0]);
        $this->assertEquals(1, $entities[0]->id);
        $this->assertEquals('User One', $entities[0]->name);
        $this->assertEquals('one@example.com', $entities[0]->emailAddress);
    }

    public function testFindBy()
    {
        // Criteria use DB column names as per Repository::findBy assumption
        $criteria = ['entity_name' => 'Specific User', 'email_address' => 'specific@example.com'];
        // OrderBy uses DB column names
        $orderBy = ['created_at' => 'DESC'];
        $limit = 5;
        $offset = 0;
        $rowData = [['repo_entity_id' => 3, 'entity_name' => 'Specific User', 'email_address' => 'specific@example.com']];

        $pdoStmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                // SQL uses metadata: table 'test_repo_entities', criteria keys are column names
                "SELECT * FROM test_repo_entities WHERE entity_name = :param0 AND email_address = :param1 ORDER BY created_at DESC LIMIT 5 OFFSET 0",
                [':param0' => 'Specific User', ':param1' => 'specific@example.com']
            )
            ->willReturn($pdoStmtMock);
        $pdoStmtMock->expects($this->once())->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn($rowData);

        $entities = $this->repository->findBy($criteria, $orderBy, $limit, $offset);
        $this->assertCount(1, $entities);
        $this->assertEquals(3, $entities[0]->id);
        $this->assertEquals('Specific User', $entities[0]->name);
    }

    public function testSaveInsertNewEntity()
    {
        $entity = new TestAttributeRepositoryEntity();
        $entity->name = 'New Repo User'; // Property name
        $entity->emailAddress = 'new.repo@example.com'; // Property name

        $this->assertNull($entity->id); // PK is null initially

        // Mock for Connection::getLastInsertId()
        $this->connectionMock->expects($this->once())
            ->method('getLastInsertId')
            ->willReturn('123'); // New ID from DB

        // Mock for the INSERT call
        $pdoStmtMockInsert = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                // SQL uses DB column names from metadata ('entity_name', 'email_address', 'created_at', 'updated_at')
                // 'repo_entity_id' (PK) is omitted due to auto-increment
                $this->stringContains("INSERT INTO test_repo_entities (entity_name, email_address, created_at, updated_at) VALUES"),
                $this->callback(function ($params) use ($entity) {
                    // Check that params match entity values, and timestamps are set
                    $this->assertEquals($entity->name, $params[':insert_entity_name']);
                    $this->assertEquals($entity->emailAddress, $params[':insert_email_address']);
                    $this->assertArrayHasKey(':insert_created_at', $params);
                    $this->assertArrayHasKey(':insert_updated_at', $params);
                    return true;
                })
            )
            ->willReturn($pdoStmtMockInsert);

        $this->assertTrue($this->repository->save($entity));
        $this->assertEquals(123, $entity->id, "Entity PK should be updated after insert.");
        $this->assertNotNull($entity->createdAt, "CreatedAt timestamp should be set.");
        $this->assertNotNull($entity->updatedAt, "UpdatedAt timestamp should be set.");
        $this->assertFalse($entity->isDirty(), "Entity should be pristine after successful save (insert).");
    }

    public function testSaveUpdateExistingEntity()
    {
        // Initial data from DB (column names)
        $initialDbData = [
            'repo_entity_id' => 5,
            'entity_name' => 'Existing Repo User',
            'email_address' => 'existing.repo@example.com',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ];
        $entity = new TestAttributeRepositoryEntity($initialDbData); // Constructor makes it pristine
        $this->assertFalse($entity->isDirty());
        $originalUpdatedAt = $entity->updatedAt;

        // Modify properties
        $entity->name = 'Updated Repo User Name'; // Property name
        $entity->emailAddress = 'updated.repo@example.com'; // Property name
        $this->assertTrue($entity->isDirty());

        // Mock for the find() call inside save() to confirm existence
        $pdoStmtMockFind = $this->createMock(PDOStatement::class);
        $pdoStmtMockFind->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn($initialDbData); // Simulate entity exists

        // Mock for the UPDATE call
        $pdoStmtMockUpdate = $this->createMock(PDOStatement::class);

        $this->connectionMock->expects($this->exactly(2)) // find() + update()
            ->method('execute')
            ->willReturnCallback(function(string $sql, array $params) use ($pdoStmtMockFind, $pdoStmtMockUpdate, $entity, $originalUpdatedAt) {
                if (str_starts_with($sql, "SELECT * FROM test_repo_entities WHERE repo_entity_id = :param0")) {
                    $this->assertEquals([':param0' => 5], $params);
                    return $pdoStmtMockFind;
                } elseif (str_starts_with($sql, "UPDATE test_repo_entities SET entity_name = :set_entity_name, email_address = :set_email_address, updated_at = :set_updated_at WHERE repo_entity_id = :param0")) {
                    $this->assertEquals('Updated Repo User Name', $params[':set_entity_name']);
                    $this->assertEquals('updated.repo@example.com', $params[':set_email_address']);
                    $this->assertNotNull($params[':set_updated_at']);
                    $this->assertNotEquals($originalUpdatedAt->format('Y-m-d H:i:s'), $params[':set_updated_at']);
                    $this->assertEquals(5, $params[':param0']);
                    return $pdoStmtMockUpdate;
                }
                throw new \LogicException("Unexpected SQL query in testSaveUpdateExistingEntity: " . $sql);
            });

        $this->assertTrue($this->repository->save($entity));
        $this->assertFalse($entity->isDirty(), "Entity should be pristine after successful save (update).");
        $this->assertEquals('Updated Repo User Name', $entity->name);
        $this->assertNotNull($entity->updatedAt);
        $this->assertNotEquals($originalUpdatedAt, $entity->updatedAt);
    }

    public function testDelete()
    {
        // Entity data uses property names for __construct if it were to map them,
        // but RepositoryTestEntity constructor takes DB data.
        // So, for instantiation, we simulate data as if it came from DB.
        $entity = new TestAttributeRepositoryEntity(['repo_entity_id' => 10]);

        $pdoStmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                // SQL uses metadata: table 'test_repo_entities', pk 'repo_entity_id'
                "DELETE FROM test_repo_entities WHERE repo_entity_id = :param0",
                [':param0' => 10]
            )
            ->willReturn($pdoStmtMock);

        $this->assertTrue($this->repository->delete($entity));
    }

    public function testSaveDoesNothingIfNotDirty()
    {
        $entity = new TestAttributeRepositoryEntity(['repo_entity_id' => 7, 'entity_name' => 'Clean User']);
        $this->assertFalse($entity->isDirty()); // Pristine after construction

        // Mock for the find() call to confirm existence (part of save logic for existing entities)
        $pdoStmtMockFind = $this->createMock(PDOStatement::class);
        $pdoStmtMockFind->method('fetch')
                        ->with(\PDO::FETCH_ASSOC)
                        ->willReturn(['repo_entity_id' => 7, 'entity_name' => 'Clean User']);

        // Only the find call is expected if entity is not dirty and exists
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with("SELECT * FROM test_repo_entities WHERE repo_entity_id = :param0 LIMIT 1", [':param0' => 7])
            ->willReturn($pdoStmtMockFind);

        $this->assertTrue($this->repository->save($entity));
    }

    // --- Relationship Eager Loading Tests ---
    public function testWithBelongsToEagerLoading()
    {
        $this->repository->with('relatedItem');

        // 1. Mock fetching the primary entities (TestAttributeRepositoryEntity)
        $primaryEntitiesData = [
            ['repo_entity_id' => 1, 'entity_name' => 'Entity 1', 'related_item_fk_id' => 10],
            ['repo_entity_id' => 2, 'entity_name' => 'Entity 2', 'related_item_fk_id' => 20],
            ['repo_entity_id' => 3, 'entity_name' => 'Entity 3', 'related_item_fk_id' => 10], // Shares related item
            ['repo_entity_id' => 4, 'entity_name' => 'Entity 4', 'related_item_fk_id' => null], // No related item
        ];
        $stmtPrimary = $this->createMock(PDOStatement::class);
        $stmtPrimary->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn($primaryEntitiesData);

        // 2. Mock fetching the related entities (RelatedEntityForRepoTest)
        // These are fetched based on the collected foreign key values (10, 20)
        $relatedEntitiesData = [
            // Related items table uses 'id' as PK as per its metadata
            ['id' => 10, 'related_name' => 'Related A'],
            ['id' => 20, 'related_name' => 'Related B'],
        ];
        $stmtRelated = $this->createMock(PDOStatement::class);
        $stmtRelated->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn($relatedEntitiesData);

        // Set up Connection mock to return these statements based on SQL
        $this->connectionMock->expects($this->exactly(2)) // One for primary, one for related
            ->method('execute')
            ->willReturnCallback(function(string $sql) use ($stmtPrimary, $stmtRelated) {
                if (str_contains($sql, "SELECT * FROM test_repo_entities")) {
                    return $stmtPrimary;
                } elseif (str_contains($sql, "SELECT * FROM related_items_for_repo WHERE id IN (:param0, :param1)")) {
                    // Check params if more specific test needed (e.g. $params are 10, 20)
                    return $stmtRelated;
                }
                throw new \LogicException("Unexpected SQL in testWithBelongsToEagerLoading: " . $sql);
            });

        $entities = $this->repository->findAll(); // This will trigger eager loading

        $this->assertCount(4, $entities);

        // Entity 1 assertions
        $this->assertNotNull($entities[0]->relatedItem);
        $this->assertInstanceOf(RelatedEntityForRepoTest::class, $entities[0]->relatedItem);
        $this->assertEquals(10, $entities[0]->relatedItem->id);
        $this->assertEquals('Related A', $entities[0]->relatedItem->relatedName);

        // Entity 2 assertions
        $this->assertNotNull($entities[1]->relatedItem);
        $this->assertInstanceOf(RelatedEntityForRepoTest::class, $entities[1]->relatedItem);
        $this->assertEquals(20, $entities[1]->relatedItem->id);
        $this->assertEquals('Related B', $entities[1]->relatedItem->relatedName);

        // Entity 3 assertions (shares relatedItem with Entity 1)
        $this->assertNotNull($entities[2]->relatedItem);
        $this->assertInstanceOf(RelatedEntityForRepoTest::class, $entities[2]->relatedItem);
        $this->assertEquals(10, $entities[2]->relatedItem->id);
        $this->assertSame($entities[0]->relatedItem, $entities[2]->relatedItem, "Should be the same instance if mapped correctly by ID");

        // Entity 4 assertions
        $this->assertNull($entities[3]->relatedItem);

        // Check that eagerLoad property is cleared
        $reflection = new \ReflectionClass(Repository::class);
        $eagerLoadProp = $reflection->getProperty('eagerLoad');
        $eagerLoadProp->setAccessible(true);
        $this->assertEmpty($eagerLoadProp->getValue($this->repository));
    }
}
