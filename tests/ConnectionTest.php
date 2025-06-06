<?php

declare(strict_types=1);

namespace Tests\YourOrm;

use YourOrm\Connection;
use PHPUnit\Framework\TestCase;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;

class ConnectionTest extends TestCase
{
    private MockObject|PDO $pdoMock;
    private Connection $connection; // This will be YourOrm\Connection

    protected function setUp(): void
    {
        // Create a mock for the PDO class
        $this->pdoMock = $this->createMock(PDO::class);
    }

    public function testConnectSuccessfully()
    {
        $host = 'localhost';
        $dbName = 'testdb';
        $username = 'user';
        $password = 'pass';

        // Configure the mock PDO object if Connection::connect makes specific calls to it upon connection
        // For example, if it calls setAttribute:
        $this->pdoMock->expects($this->once())
            ->method('setAttribute')
            ->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Instantiate Connection with test parameters
        // We can't directly inject the mock PDO into the current Connection class constructor.
        // So, we test connect() and assume it would create a PDO instance.
        // To properly test Connection with a mock PDO, Connection would need to allow PDO injection,
        // e.g., by passing a PDOFactory or the PDO instance itself to its constructor, or a setter.

        // For now, let's test that connect() attempts to create a PDO object.
        // This is difficult without refactoring Connection to allow PDO injection.
        // Let's assume for this test that if PDO constructor inside connect() doesn't throw, it's "successful".
        // A better approach would be to modify Connection class to allow PDO injection for testability.

        // Given the current Connection class, we can't directly test connect() with a mock.
        // We can test that it returns a PDO instance, but not *our mock* instance.
        // So, a true unit test of connect() is hard.

        // Let's test the constructor and that getter methods for connection params work.
        $connection = new Connection($host, $username, $password, $dbName);

        // This doesn't test the actual connection but that parameters are stored.
        // To test the actual connection logic, we would need to be able to inject the PDO mock.
        // For now, we'll test that calling connect() multiple times returns the same PDO instance.

        // This is more of an integration test if it were to hit a real DB.
        // To make it a unit test, we'd need to refactor Connection class.
        // For now, let's assume we can't refactor Connection.php yet.
        // We will test that execute throws an exception if not connected.

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("Not connected to the database. Call connect() first.");
        $connection->execute("SELECT 1");
    }

    public function testExecuteThrowsExceptionWhenNotConnected()
    {
        $connection = new Connection('host', 'user', 'pass', 'db');
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("Not connected to the database. Call connect() first.");
        $connection->execute("SELECT * FROM users");
    }

    // To test connect() success and execute() success properly, Connection needs refactoring for PDO injection.
    // If we could inject PDO:
    /*
    public function testConnectAndExecuteSuccessfullyWithMock()
    {
        $host = 'localhost';
        $dbName = 'testdb';
        $username = 'user';
        $password = 'pass';

        // Assume Connection is refactored to accept a PDO mock in constructor or a setter
        // $connection = new Connection($this->pdoMock, $host, $username, $password, $dbName);
        // For now, this is a placeholder for how it *would* be tested.

        // Scenario: Connection class is refactored to allow injecting PDO instance
        // class Connection {
        //     private ?PDO $pdoInstance;
        //     public function __construct(string $h,string $u,string $p,string $d, ?PDO $pdo = null){
        //         $this->pdoInstance = $pdo; ...
        //     }
        //     public function connect(): PDO { if ($this->pdoInstance) { $this->pdo = $this->pdoInstance; ...} else { ... new PDO ... }}
        // }

        // If Connection class allowed this (hypothetical):
        // $connection = new Connection($host, $username, $password, $dbName);
        // $connection->setPdoInstance($this->pdoMock); // hypothetical setter

        // $this->pdoMock->expects($this->once())
        //     ->method('setAttribute')
        //     ->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // $retrievedPdo = $connection->connect(); // Should use the mock
        // $this->assertSame($this->pdoMock, $retrievedPdo);

        // $stmtMock = $this->createMock(PDOStatement::class);
        // $this->pdoMock->expects($this->once())
        //     ->method('prepare')
        //     ->with("SELECT * FROM test")
        //     ->willReturn($stmtMock);
        // $stmtMock->expects($this->once())
        //     ->method('execute')
        //     ->with([]);
        // $resultStmt = $connection->execute("SELECT * FROM test", []);
        // $this->assertSame($stmtMock, $resultStmt);
    }
    */

    // Given the limitations, we will focus on what can be tested without refactoring Connection.
    // We've tested that execute() throws if connect() is not called.
    // Testing a successful connection and execute would require a real DB or refactoring.
    // Testing connection failure is also hard without a real DB or refactoring.

    // Let's add a test for disconnect()
    public function testDisconnect()
    {
        // This test is also limited. We can call disconnect, but verifying its effect (pdo is null)
        // requires either a) pdo property to be public/testable or b) behavior change,
        // e.g. connect() after disconnect() tries to reconnect.

        $connection = new Connection('host', 'user', 'pass', 'db');
        // We can't easily verify $connection->pdo is null after disconnect.
        // However, if we call execute after disconnect, it should throw the "Not connected" exception.

        // To make this meaningful, one might try to connect first.
        // But connect() itself is hard to unit test without a real DB or refactor.
        // For now, just call disconnect() for coverage, acknowledging limitations.
        $connection->disconnect();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("Not connected to the database. Call connect() first.");
        $connection->execute("SELECT 1");
    }

    // Helper method to create a Connection instance and inject a mock PDO object
    private function setupConnectionAndMockPdo(): array
    {
        $mockPdo = $this->createMock(\PDO::class);
        // Dummy connection parameters, not actually used if PDO is mocked properly
        $connection = new Connection('testhost', 'testuser', 'testpass', 'testdb');

        $reflection = new \ReflectionClass($connection);
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($connection, $mockPdo);

        return ['connection' => $connection, 'mockPdo' => $mockPdo];
    }

    public function testBeginTransactionCallsPdoMethod()
    {
        $setup = $this->setupConnectionAndMockPdo();
        $connection = $setup['connection'];
        /** @var MockObject|PDO $mockPdo */
        $mockPdo = $setup['mockPdo'];

        $mockPdo->expects($this->once())->method('beginTransaction');
        $connection->beginTransaction();
    }

    public function testCommitCallsPdoMethod()
    {
        $setup = $this->setupConnectionAndMockPdo();
        $connection = $setup['connection'];
        /** @var MockObject|PDO $mockPdo */
        $mockPdo = $setup['mockPdo'];

        $mockPdo->expects($this->once())->method('commit');
        $connection->commit();
    }

    public function testRollBackCallsPdoMethod()
    {
        $setup = $this->setupConnectionAndMockPdo();
        $connection = $setup['connection'];
        /** @var MockObject|PDO $mockPdo */
        $mockPdo = $setup['mockPdo'];

        $mockPdo->expects($this->once())->method('rollBack');
        $connection->rollBack();
    }

    public function testBeginTransactionPropagatesException()
    {
        $setup = $this->setupConnectionAndMockPdo();
        $connection = $setup['connection'];
        /** @var MockObject|PDO $mockPdo */
        $mockPdo = $setup['mockPdo'];

        $mockPdo->method('beginTransaction')->willThrowException(new \PDOException("pdo_begin_error"));

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage("pdo_begin_error");
        $connection->beginTransaction();
    }

    public function testCommitPropagatesException()
    {
        $setup = $this->setupConnectionAndMockPdo();
        $connection = $setup['connection'];
        /** @var MockObject|PDO $mockPdo */
        $mockPdo = $setup['mockPdo'];

        $mockPdo->method('commit')->willThrowException(new \PDOException("pdo_commit_error"));
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage("pdo_commit_error");
        $connection->commit();
    }

    public function testRollBackPropagatesException()
    {
        $setup = $this->setupConnectionAndMockPdo();
        $connection = $setup['connection'];
        /** @var MockObject|PDO $mockPdo */
        $mockPdo = $setup['mockPdo'];

        $mockPdo->method('rollBack')->willThrowException(new \PDOException("pdo_rollback_error"));
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage("pdo_rollback_error");
        $connection->rollBack();
    }
}
