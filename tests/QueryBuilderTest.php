<?php

declare(strict_types=1);

namespace Tests\YourOrm;

// Corrected use statements
use YourOrm\Connection;
use YourOrm\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PDOStatement; // For mocking execute if we go that far
use PDO;          // For PDO constants like PDO::FETCH_ASSOC

class QueryBuilderTest extends TestCase
{
    private MockObject|Connection $connectionMock;
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(\YourOrm\Connection::class);
        $this->qb = new \YourOrm\QueryBuilder($this->connectionMock);
    }

    public function testSelectQuery()
    {
        $this->qb->select('id', 'name')
            ->from('users');
        $this->assertEquals("SELECT id, name FROM users", $this->qb->getSql());
    }

    public function testSelectQueryWithAlias()
    {
        $this->qb->select('u.id', 'p.name')
            ->from('users', 'u');
        $this->assertEquals("SELECT u.id, p.name FROM users AS u", $this->qb->getSql());
    }

    public function testSelectQueryDefaultsToAllColumns()
    {
        $this->qb->select()->from('users');
        $this->assertEquals("SELECT * FROM users", $this->qb->getSql());

        // Test again to ensure reset logic works if select() is called multiple times
        // or if other query types were called before.
        // QueryBuilder's getSql/fetch/fetchAll/execute/insert methods reset the state.
        // So we need a new QB for a new independent test, or ensure reset.
        // setUp creates a new qb for each test method, so this is fine.
        $this->qb->from('posts'); // Implicit select *
        $this->assertEquals("SELECT * FROM posts", $this->qb->getSql());
    }


    public function testWhereClause()
    {
        $this->qb->select()
            ->from('users')
            ->where('id', '=', 1);
        $this->assertEquals("SELECT * FROM users WHERE id = :param0", $this->qb->getSql());
        $this->assertEquals([':param0' => 1], $this->qb->getParameters());
    }

    public function testMultipleWhereClausesAndOrWhere()
    {
        $this->qb->select()
            ->from('users')
            ->where('status', '=', 'active')
            ->where('age', '>', 30, 'AND') // Explicit AND
            ->orWhere('role', '=', 'admin');

        $expectedSql = "SELECT * FROM users WHERE status = :param0 AND age > :param1 OR role = :param2";
        $this->assertEquals($expectedSql, $this->qb->getSql());
        $expectedParams = [
            ':param0' => 'active',
            ':param1' => 30,
            ':param2' => 'admin',
        ];
        $this->assertEquals($expectedParams, $this->qb->getParameters());
    }

    public function testOrderByLimitOffset()
    {
        $this->qb->select()
            ->from('articles')
            ->where('published', '=', true)
            ->orderBy('created_at', 'DESC')
            ->orderBy('title', 'ASC')
            ->limit(10)
            ->offset(20);

        $expectedSql = "SELECT * FROM articles WHERE published = :param0 ORDER BY created_at DESC, title ASC LIMIT 10 OFFSET 20";
        $this->assertEquals($expectedSql, $this->qb->getSql());
        $this->assertEquals([':param0' => true], $this->qb->getParameters());
    }

    public function testInsertReturnsSelf()
    {
        $result = $this->qb->insert('users', ['name' => 'Test']);
        $this->assertSame($this->qb, $result, "Insert method should return self (QueryBuilder instance).");
    }

    public function testGetSqlForInsert()
    {
        $this->qb->insert('users', ['name' => 'John Doe', 'email' => 'john@example.com']);
        $expectedSql = "INSERT INTO users (name, email) VALUES (:insert_name, :insert_email)";
        $this->assertEquals($expectedSql, $this->qb->getSql());
    }

    public function testGetParametersForInsert()
    {
        $this->qb->insert('users', ['name' => 'Jane Doe', 'age' => 30]);
        $expectedParams = [':insert_name' => 'Jane Doe', ':insert_age' => 30];
        $this->assertEquals($expectedParams, $this->qb->getParameters());
    }

    public function testExecuteInsert()
    {
        $table = 'users';
        $data = ['username' => 'tester'];
        $expectedSql = "INSERT INTO users (username) VALUES (:insert_username)";
        $expectedParams = [':insert_username' => 'tester'];

        $stmtMock = $this->createMock(\PDOStatement::class);
        $this->connectionMock
            ->expects($this->once())
            ->method('execute')
            ->with($expectedSql, $expectedParams)
            ->willReturn($stmtMock);

        $result = $this->qb->insert($table, $data)->execute();
        $this->assertTrue($result, "Execute should return true on successful insert.");
    }

    public function testInsertWithEmptyDataThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot insert empty data set.");
        $this->qb->insert('users', []);
    }

    public function testExecuteInsertHandlesPdoException()
    {
        $table = 'users';
        $data = ['username' => 'tester_exception'];
        $expectedSql = "INSERT INTO users (username) VALUES (:insert_username)";
        $expectedParams = [':insert_username' => 'tester_exception'];

        $originalPdoException = new \PDOException("Database error");
        // Assuming Connection::execute throws QueryExecutionException, which wraps PDOException
        // If QueryBuilder::execute catches QueryExecutionException from Connection::execute
        // and returns false.
        $this->connectionMock
            ->expects($this->once())
            ->method('execute')
            ->with($expectedSql, $expectedParams)
            ->willThrowException(new \YourOrm\Exception\QueryExecutionException($originalPdoException, $expectedSql, $expectedParams));

        $result = $this->qb->insert($table, $data)->execute();
        $this->assertFalse($result, "Execute should return false when connection throws PDOException/QueryExecutionException.");
    }


    public function testUpdateQuery()
    {
        $table = 'users';
        $data = ['status' => 'inactive'];

        // Mock the execute call for update
         $stmtMock = $this->createMock(\PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                "UPDATE users SET status = :set_status WHERE id = :param0",
                [':set_status' => 'inactive', ':param0' => 1]
            )
            ->willReturn($stmtMock);

        $this->qb->update($table, $data)
            ->where('id', '=', 1);

        // getSql() and getParameters() would reflect the state *before* execute() resets them
        $this->assertEquals(
            "UPDATE users SET status = :set_status WHERE id = :param0",
            $this->qb->getSql()
        );
         $this->assertEquals(
            [':set_status' => 'inactive', ':param0' => 1],
            $this->qb->getParameters()
        );

        $result = $this->qb->execute(); // This will call the mocked Connection::execute
        $this->assertTrue($result);
    }

    public function testDeleteQuery()
    {
        $table = 'users';

        // Mock the execute call for delete
        $stmtMock = $this->createMock(\PDOStatement::class);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with(
                "DELETE FROM users WHERE id = :param0",
                [':param0' => 1]
            )
            ->willReturn($stmtMock);

        $this->qb->delete($table)
            ->where('id', '=', 1);

        $this->assertEquals(
            "DELETE FROM users WHERE id = :param0",
            $this->qb->getSql()
        );
        $this->assertEquals(
            [':param0' => 1],
            $this->qb->getParameters()
        );

        $result = $this->qb->execute();
        $this->assertTrue($result);
    }

    public function testUpdateQueryThrowsExceptionWithoutWhere()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("UPDATE statement must have a WHERE clause.");

        $this->qb->update('users', ['name' => 'Test'])
            // ->where('id','=',1) // No where clause
            ->getSql(); // getSql() for UPDATE/DELETE will trigger the check
    }

    public function testDeleteQueryThrowsExceptionWithoutWhere()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("DELETE statement must have a WHERE clause.");

        $this->qb->delete('users')
            // ->where('id','=',1) // No where clause
            ->getSql(); // getSql() for UPDATE/DELETE will trigger the check
    }

    // Test fetch and fetchAll SQL generation (execution requires more involved mocking or a live DB)
    public function testFetchSqlGeneration()
    {
        $this->qb->select('id')->from('users')->where('id', '=', 1);
        // fetch() calls limit(1) then getSql()
        $expectedSql = "SELECT id FROM users WHERE id = :param0 LIMIT 1";
        // Cannot directly call getSql after fetch in the same chain in a test easily
        // because fetch executes and resets. So we check the components.
        // This test is more about the implicit limit(1) in fetch.
        // The actual getSql() for fetch is tested via mocking execute on connection.

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['id' => 1]);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with($expectedSql, [':param0' => 1])
            ->willReturn($stmtMock);

        $result = $this->qb->fetch();
        $this->assertEquals(['id' => 1], $result);
    }

    public function testFetchAllSqlGeneration()
    {
        $this->qb->select('id', 'name')->from('users')->where('status', '=', 'active');
        $expectedSql = "SELECT id, name FROM users WHERE status = :param0";
        $expectedParams = [':param0' => 'active'];

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn([['id' => 1, 'name' => 'A']]);
        $this->connectionMock->expects($this->once())
            ->method('execute')
            ->with($expectedSql, $expectedParams)
            ->willReturn($stmtMock);

        $result = $this->qb->fetchAll();
        $this->assertEquals([['id' => 1, 'name' => 'A']], $result);
    }

    public function testResetLogic()
    {
        // Build a query
        $this->qb->select('name')->from('widgets')->where('id', '=', 100);
        $this->assertEquals("SELECT name FROM widgets WHERE id = :param0", $this->qb->getSql());
        $this->assertEquals([':param0' => 100], $this->qb->getParameters());

        // Simulate execution which would call reset (e.g. fetchAll)
        // We can call reset directly if we want to test it in isolation, but it's private.
        // Instead, execute a query that resets.
        $baseStmtMock = $this->createMock(\PDOStatement::class); // Mock for most execute calls
        $baseStmtMock->method('fetchAll')->willReturn([]); // For the fetchAll call specifically

        $this->connectionMock->expects($this->any()) // Allow multiple calls to execute
            ->method('execute')
            ->willReturn($baseStmtMock); // Default mock for execute

        $this->qb->fetchAll(); // This will use the $baseStmtMock

        // After reset (e.g. after fetchAll), state should be clear for a new query
        // A new SELECT query
        $this->qb->select('data')->from('gadgets');
        $this->assertEquals("SELECT data FROM gadgets", $this->qb->getSql());
        $this->assertEquals([], $this->qb->getParameters(), "Parameters should be empty after reset and new query");

        // A new INSERT query (insert also resets)
        // Ensure the mock for 'execute' can handle this specific insert call if needed,
        // or that the default $baseStmtMock is sufficient.
        // If insert makes specific PDOStatement calls, a more specific mock might be needed here.
        $this->qb->insert('items', ['col' => 'val']); // This resets

        // Check state after insert reset
        $this->qb->select('foo')->from('bars');
        $this->assertEquals("SELECT foo FROM bars", $this->qb->getSql());
        $this->assertEquals([], $this->qb->getParameters());
    }
}
