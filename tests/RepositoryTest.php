<?php

declare(strict_types=1);

namespace Tests;

use App\Connection;
use App\Repository;
use App\Entity;
use PHPUnit\Framework\TestCase;

use App\Connection;
use App\Entity;
use App\QueryBuilder;
use App\Repository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// Using TestEntity from EntityTest.php for convenience
// If EntityTest.php is not always loaded, this might need its own TestEntity definition or proper autoloading.
// For PHPUnit, if they are in the same test suite and autoloaded via composer, it should be fine.
// require_once __DIR__ . '/EntityTest.php'; // Or ensure TestEntity is autoloadable

class TestRepositoryEntity extends Entity // Defined here if not reliably autoloaded from EntityTest
{
    public string $id; // Make properties public for easier test setup if needed
    public string $name;

    public function getTableName(): string
    {
        return 'test_repository_entities';
    }

    public function getPrimaryKeyName(): string
    {
        return 'id';
    }
    // Helper to easily create entity with data for testing
    public static function create(array $data): self
    {
        $entity = new self($data);
        // If constructor doesn't make initial data "dirty" for inserts,
        // and save relies on dirtyData, then we might need to manually set them.
        foreach ($data as $key => $value) {
            $entity->{$key} = $value; // Trigger __set if necessary
        }
        return $entity;
    }
}


class RepositoryTest extends TestCase
{
    private MockObject|Connection $connectionMock;
    private MockObject|QueryBuilder $qbMock;
    private Repository $repository;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(Connection::class);
        $this->qbMock = $this->createMock(QueryBuilder::class);

        // Configure the Connection mock to return the QueryBuilder mock
        // This is not how Repository gets QueryBuilder. Repository news it up.
        // So, we need to inject the QB mock into Repository.
        // This requires refactoring Repository or testing it more like an integration test.

        // Current Repository constructor: new QueryBuilder($this->connection)
        // To test Repository in isolation, we need to inject a mock QueryBuilder.
        // Option 1: Refactor Repository to accept QueryBuilder in constructor.
        // Option 2: Use a more complex setup, e.g. a factory or service locator (not ideal for unit tests).
        // Option 3: Live with a more integrated test for Repository for now.

        // Let's assume we cannot refactor Repository for now.
        // This means when we instantiate Repository, it will create its own QueryBuilder.
        // This makes mocking QueryBuilder's behavior for Repository tests hard.

        // Workaround:
        // We can't directly mock the QueryBuilder used by Repository unless we refactor Repository.
        // So, these tests will be more integration-like for Repository and QueryBuilder.
        // We will assert that the *real* QueryBuilder (used by Repository) produces certain SQL
        // and that Repository processes the results correctly.
        // To do this, the QueryBuilder's chained methods must return $this->qbMock for further chaining.

        // This setup is for if Repository *accepted* a QB mock.
        // $this->repository = new Repository($this->connectionMock, TestRepositoryEntity::class, $this->qbMock);

        // Since Repository creates its own QueryBuilder using its Connection,
        // we can only mock the Connection. The QueryBuilder will be real.
        // This means we can't easily mock $qb->fetch() to return specific data.
        // We would be testing QueryBuilder's fetch through Repository.

        // Let's stick to the original plan: Mock QueryBuilder.
        // This implies Repository needs to be refactored or we accept limitations.
        // For this exercise, I will proceed as if Repository *can* be injected with a QB mock.
        // This is a common approach in designing testable code.
        // If the actual code of Repository.php cannot be changed for this subtask,
        // then these tests would need to be rewritten.
        // (Assuming Repository constructor is: `__construct(Connection $c, string $ec, QueryBuilder $qb)`)
        // If not, the alternative is to mock Connection and have it return mocked PDOStatements
        // that Repository's internal QueryBuilder would use. This is more indirect.

        // Let's assume a refactored Repository for testability:
        // E.g. Repository constructor: public function __construct(Connection $connection, string $entityClass, QueryBuilder $qb)
        // If this is not the case, these tests will not work as intended.
        // The prompt for Repository implementation didn't include injecting QB. It created it.
        // So, these tests must be adapted.

        // New strategy: The Repository creates `new QueryBuilder($this->connection)`.
        // We mock `Connection`. `QueryBuilder` methods like `fetch`, `fetchAll`, `execute`, `insert`
        // ultimately call `Connection::execute()`. We mock *that*.

        $this->repository = new Repository($this->connectionMock, TestRepositoryEntity::class);
        // We need QueryBuilder to be accessible or its chained methods to be mockable IF Repository used it that way.
        // But Repository NEWS UP QueryBuilder.
        // So, we mock Connection.execute() and expect QueryBuilder to form correct SQL to pass to it.
    }

    public function testFind()
    {
        $expectedEntity = new TestRepositoryEntity(['id' => '1', 'name' => 'Test User']);
        $pdoStmtMock = $this->createMock(PDOStatement::class);

        // Configure Connection mock for the sequence of calls made by QueryBuilder through Repository's find()
        // Repository -> QueryBuilder -> select()->from()->where()->fetch()
        // QueryBuilder::fetch() -> Connection::execute(sql, params) -> PDOStatement::fetch()

        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                "SELECT * FROM test_repository_entities WHERE id = :param0 LIMIT 1", // QueryBuilder adds LIMIT 1 for fetch()
                [':param0' => '1']
            )
            ->willReturn($pdoStmtMock);

        $pdoStmtMock->expects($this->once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn(['id' => '1', 'name' => 'Test User']);

        $entity = $this->repository->find('1');
        $this->assertInstanceOf(TestRepositoryEntity::class, $entity);
        $this->assertEquals('1', $entity->id);
        $this->assertEquals('Test User', $entity->name);
    }

    public function testFindReturnsNullIfEntityNotFound()
    {
        $pdoStmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with("SELECT * FROM test_repository_entities WHERE id = :param0 LIMIT 1", [':param0' => 'nonexistent'])
            ->willReturn($pdoStmtMock);
        $pdoStmtMock->expects($this->once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn(false);

        $this->assertNull($this->repository->find('nonexistent'));
    }

    public function testFindAll()
    {
        $rowData = [
            ['id' => '1', 'name' => 'User One'],
            ['id' => '2', 'name' => 'User Two'],
        ];
        $pdoStmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with("SELECT * FROM test_repository_entities", [])
            ->willReturn($pdoStmtMock);
        $pdoStmtMock->expects($this->once())->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn($rowData);

        $entities = $this->repository->findAll();
        $this->assertCount(2, $entities);
        $this->assertInstanceOf(TestRepositoryEntity::class, $entities[0]);
        $this->assertEquals('User One', $entities[0]->name);
        $this->assertInstanceOf(TestRepositoryEntity::class, $entities[1]);
        $this->assertEquals('User Two', $entities[1]->name);
    }

    public function testFindBy()
    {
        $criteria = ['status' => 'active', 'type' => 'member'];
        $orderBy = ['created_at' => 'DESC'];
        $limit = 10;
        $offset = 0;
        $rowData = [['id' => '3', 'name' => 'Active Member', 'status' => 'active', 'type' => 'member']];

        $pdoStmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            // QueryBuilder generates params like :param0, :param1 ...
            ->with(
                "SELECT * FROM test_repository_entities WHERE status = :param0 AND type = :param1 ORDER BY created_at DESC LIMIT 10 OFFSET 0",
                [':param0' => 'active', ':param1' => 'member'] // Params match where() calls
            )
            ->willReturn($pdoStmtMock);
        $pdoStmtMock->expects($this->once())->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn($rowData);

        $entities = $this->repository->findBy($criteria, $orderBy, $limit, $offset);
        $this->assertCount(1, $entities);
        $this->assertEquals('Active Member', $entities[0]->name);
    }


    public function testSaveInsertNewEntity()
    {
        // Entity is new (no ID or ID is 0/null) and find() would return null for its ID.
        $entity = new TestRepositoryEntity(); // Create with no initial data to ensure __set is used
        $entity->name = 'New User';
        $entity->email = 'new@example.com';
        // $entity->id is null

        // Mock for the find() call inside save() to check existence (if PK is set)
        // Since $entity->id is null, find() won't be called for existence check.

        // Mock for the insert call
        $pdoStmtMockInsert = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                "INSERT INTO test_repository_entities (name, email) VALUES (:insert_name, :insert_email)",
                [':insert_name' => 'New User', ':insert_email' => 'new@example.com']
            )
            ->willReturn($pdoStmtMockInsert);
        // Potentially mock lastInsertId if Repository::save tried to set it back

        $this->assertTrue($this->repository->save($entity));
        $this->assertFalse($entity->isDirty(), "Entity should be pristine after successful save (insert).");
    }

    public function testSaveUpdateExistingEntity()
    {
        // Entity exists, has an ID, and isDirty() is true.
        $entity = new TestRepositoryEntity(['id' => '5', 'name' => 'Existing User']); // Initial state
        $entity->markAsPristine(); // Simulate it's fetched from DB

        $entity->name = 'Updated User Name'; // Make it dirty

        // Mock for the find() call inside save() to confirm existence
        $pdoStmtMockFind = $this->createMock(PDOStatement::class);
        $this->connectionMock->expects($this->atLeastOnce()) // Could be called multiple times if save logic is complex
            ->method('execute')
            ->willReturnCallback(function($sql, $params) use ($pdoStmtMockFind, $entity) {
                // This callback needs to differentiate between the find call and the update call.
                if (str_starts_with($sql, "SELECT * FROM test_repository_entities WHERE id = :param0 LIMIT 1")) {
                    if ($params[':param0'] === $entity->id) { // Check if it's finding the entity being saved
                         $pdoStmtMockFind->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn(['id' => $entity->id, 'name' => 'Existing User']);
                         return $pdoStmtMockFind;
                    }
                } elseif (str_starts_with($sql, "UPDATE test_repository_entities SET name = :set_name WHERE id = :param0")) {
                     $pdoStmtMockUpdate = $this->createMock(PDOStatement::class);
                     // $pdoStmtMockUpdate->method('rowCount')->willReturn(1); // if save checks rowCount
                     return $pdoStmtMockUpdate;
                }
                // Fallback for unexpected calls
                $unexpectedStmt = $this->createMock(PDOStatement::class);
                $unexpectedStmt->method('fetch')->willReturn(false);
                $unexpectedStmt->method('fetchAll')->willReturn([]);
                return $unexpectedStmt;
            });

        $this->assertTrue($this->repository->save($entity));
        $this->assertFalse($entity->isDirty(), "Entity should be pristine after successful save (update).");
        $this->assertEquals('Updated User Name', $entity->name); // Check if data in entity is also updated by markAsPristine
    }


    public function testDelete()
    {
        $entity = new TestRepositoryEntity(['id' => '10']);
        // $entity->markAsPristine(); // Not strictly necessary for delete test

        $pdoStmtMock = $this->createMock(PDOStatement::class);
        // $pdoStmtMock->method('rowCount')->willReturn(1); // If Repository::delete checks rowCount

        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                "DELETE FROM test_repository_entities WHERE id = :param0",
                [':param0' => '10']
            )
            ->willReturn($pdoStmtMock);

        $this->assertTrue($this->repository->delete($entity));
    }

    public function testSaveDoesNothingIfNotDirty()
    {
        $entity = new TestRepositoryEntity(['id' => '7', 'name' => 'Clean User']);
        $entity->markAsPristine(); // Entity is not dirty

        // Mock for the find() call to confirm existence
        $pdoStmtMockFind = $this->createMock(PDOStatement::class);
        $pdoStmtMockFind->method('fetch')
                        ->with(\PDO::FETCH_ASSOC)
                        ->willReturn(['id' => '7', 'name' => 'Clean User']);

        $this->connectionMock->expects($this->once()) // Only the find call
            ->method('execute')
            ->with("SELECT * FROM test_repository_entities WHERE id = :param0 LIMIT 1", [':param0' => '7'])
            ->willReturn($pdoStmtMockFind);

        // No INSERT or UPDATE execute should be called
        // connectionMock will fail the test if execute is called more than once (for the find)

        $this->assertTrue($this->repository->save($entity));
    }
}
