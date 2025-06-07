<?php

declare(strict_types=1);

namespace Tests\CoralORM\Migration;

use CoralORM\Connection;
use CoralORM\Migration\Migrator;
use CoralORM\Migration\SchemaBuilder;
use CoralORM\Migration\AbstractMigration;
use CoralORM\QueryBuilder; // Used by Migrator
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PDOException; // For testing ensureMigrationsTableExists
use PDOStatement; // For mocking QueryBuilder results

// Dummy migration class for testing runUp/runDown
class CreateUsersTable_20230101000000 extends AbstractMigration
{
    public static bool $upCalled = false;
    public static bool $downCalled = false;
    public static bool $postUpCalled = false;
    public static ?Connection $postUpConnection = null;


    public function up(SchemaBuilder $schema): void { self::$upCalled = true; }
    public function down(SchemaBuilder $schema): void { self::$downCalled = true; }
    public function postUp(Connection $connection): void
    {
        self::$postUpCalled = true;
        self::$postUpConnection = $connection;
    }
}
// Another dummy migration
class AddStatusToUsersTable_20230102000000 extends AbstractMigration
{
    public function up(SchemaBuilder $schema): void {}
    public function down(SchemaBuilder $schema): void {}
    // No postUp here
}


class MigratorTest extends TestCase
{
    private MockObject|Connection $connectionMock;
    private MockObject|QueryBuilder $qbMock; // Migrator uses QueryBuilder internally
    private string $migrationsPath;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(\CoralORM\Connection::class);
        // $this->qbMock = $this->createMock(\CoralORM\QueryBuilder::class); // Not directly used due to Migrator's internal QB instantiation

        $this->migrationsPath = sys_get_temp_dir() . '/coralorm_test_migrations_' . uniqid();
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0777, true);
        }

        $stmtMock = $this->createMock(PDOStatement::class);
        $this->connectionMock->method('execute')->willReturn($stmtMock);
        $stmtMock->method('fetch')->willReturn(null);
        $stmtMock->method('fetchAll')->willReturn([]);

        $this->migrator = new Migrator($this->connectionMock, $this->migrationsPath);

        // Reset static properties for each test
        CreateUsersTable_20230101000000::$upCalled = false;
        CreateUsersTable_20230101000000::$downCalled = false;
        CreateUsersTable_20230101000000::$postUpCalled = false;
        CreateUsersTable_20230101000000::$postUpConnection = null;
    }

    protected function tearDown(): void
    {
        // Clean up dummy migration files and directory
        if (is_dir($this->migrationsPath)) {
            $files = glob($this->migrationsPath . '/*.php');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->migrationsPath);
        }
    }

    private function createDummyMigrationFile(string $name, string $className, string $namespace = 'CoralORM\\Migration\\Database', bool $withPostUp = false): void
    {
        $postUpMethod = $withPostUp ?
            'public static bool $postUpCalled = false; public static ?\CoralORM\Connection $postUpConnection = null; public function postUp(\CoralORM\Connection $c): void { self::$postUpCalled = true; self::$postUpConnection = $c; }' :
            '';
        $content = <<<PHP
<?php
namespace {$namespace};
use CoralORM\Migration\AbstractMigration;
use CoralORM\Migration\SchemaBuilder;
use CoralORM\Connection; // Ensure Connection is available for postUp

class {$className} extends AbstractMigration {
    public static bool \$upCalled = false;
    public static bool \$downCalled = false;
    {$postUpMethod}

    public function up(SchemaBuilder \$s): void { self::\$upCalled = true; }
    public function down(SchemaBuilder \$s): void { self::\$downCalled = true; }
}
PHP;
        // Reset static flags for the specific class being created, if it's one of the main test classes
        if ($className === 'CreateUsersTable_20230101000000') {
            CreateUsersTable_20230101000000::$upCalled = false;
            CreateUsersTable_20230101000000::$downCalled = false;
            if ($withPostUp) {
                 CreateUsersTable_20230101000000::$postUpCalled = false;
                 CreateUsersTable_20230101000000::$postUpConnection = null;
            }
        }

        file_put_contents($this->migrationsPath . '/' . $name . '.php', $content);
    }

    public function testEnsureMigrationsTableExistsWhenTableDoesNotExist()
    {
        // Setup: Simulate table not existing by having the initial SELECT 1 FROM migrations throw PDOException
        $this->connectionMock->expects($this->atLeastOnce())
            ->method('execute')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, "SELECT 1 FROM `migrations` LIMIT 1")) {
                    throw new PDOException("Table not found", "42S02");
                }
                // For the CREATE TABLE statement by SchemaBuilder
                $stmt = $this->createMock(PDOStatement::class);
                $this->assertStringContainsString("CREATE TABLE `migrations`", $sql);
                return $stmt;
            });

        // Re-initialize migrator to trigger ensureMigrationsTableExists with the mocked behavior
        new Migrator($this->connectionMock, $this->migrationsPath);
    }


    public function testGetAllMigrationFiles()
    {
        $this->createDummyMigrationFile('20230101000000_CreateUsersTable', 'CreateUsersTable_20230101000000');
        $this->createDummyMigrationFile('20230102000000_AddStatusToUsersTable', 'AddStatusToUsersTable_20230102000000');
        file_put_contents($this->migrationsPath . '/not_a_migration.txt', 'hello'); // Non-PHP file
        file_put_contents($this->migrationsPath . '/InvalidNameFormat.php', '<?php class Invalid {}');


        $files = $this->migrator->getAllMigrationFiles();
        $this->assertCount(2, $files);
        $this->assertEquals('20230101000000_CreateUsersTable', $files[0]);
        $this->assertEquals('20230102000000_AddStatusToUsersTable', $files[1]);
    }

    public function testGetExecutedMigrations()
    {
        $stmtMock = $this->createMock(PDOStatement::class);
        $expectedData = [
            ['migration' => '20230101000000_CreateUsersTable', 'batch' => 1, 'executed_at' => '...'],
        ];
        $stmtMock->method('fetchAll')->willReturn($expectedData);

        // This is tricky because Migrator creates its own QB.
        // We need Connection::execute, when called with SQL from QB for getExecutedMigrations, to return this stmtMock.
        $this->connectionMock->method('execute')
            ->with($this->stringContains("SELECT migration, batch, executed_at FROM `migrations` ORDER BY migration ASC"))
            ->willReturn($stmtMock);

        $executed = $this->migrator->getExecutedMigrations();
        $this->assertArrayHasKey('20230101000000_CreateUsersTable', $executed);
        $this->assertEquals(1, $executed['20230101000000_CreateUsersTable']['batch']);
    }

    public function testGetPendingMigrations()
    {
        $this->createDummyMigrationFile('20230101000000_CreateUsersTable', 'CreateUsersTable_20230101000000');
        $this->createDummyMigrationFile('20230102000000_AddStatusToUsersTable', 'AddStatusToUsersTable_20230102000000');

        $stmtMock = $this->createMock(PDOStatement::class);
        $executedDbData = [
            ['migration' => '20230101000000_CreateUsersTable', 'batch' => 1, 'executed_at' => '...'],
        ];
        $stmtMock->method('fetchAll')->willReturn($executedDbData);
        $this->connectionMock->method('execute')
             ->with($this->stringContains("SELECT migration, batch, executed_at FROM `migrations`"))
             ->willReturn($stmtMock);

        $pending = $this->migrator->getPendingMigrations();
        $this->assertCount(1, $pending);
        $this->assertEquals('20230102000000_AddStatusToUsersTable', $pending[0]);
    }

    public function testRunUpAndPostUp()
    {
        $migrationName = '20230101000000_CreateUsersTable';
        $className = 'CreateUsersTable_20230101000000'; // This class is defined at the top of the file

        // Create the dummy file using the class defined at the top, ensuring its static properties are used
        $this->createDummyMigrationFileForPredefinedClass($migrationName, $className);


        $logStmtMock = $this->createMock(PDOStatement::class);
        $batchStmtMock = $this->createMock(PDOStatement::class);
        $batchStmtMock->method('fetch')->willReturn(['max_batch' => null]);

        $this->connectionMock->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function(string $sql) use ($batchStmtMock, $logStmtMock, $migrationName){
                if (str_contains($sql, "MAX(batch)")) {
                    return $batchStmtMock;
                } elseif (str_contains($sql, "INSERT INTO `migrations`")) {
                    $this->assertStringContainsString("`migration` = '{$migrationName}'", $sql);
                    $this->assertStringContainsString("'batch' = 1", $sql);
                    return $logStmtMock;
                }
                return $this->createMock(PDOStatement::class); // For other calls if any
            });

        $result = $this->migrator->runUp($migrationName);
        $this->assertTrue($result);
        $this->assertTrue(CreateUsersTable_20230101000000::$upCalled, "up() method should have been called.");
        $this->assertTrue(CreateUsersTable_20230101000000::$postUpCalled, "postUp() method should have been called.");
        $this->assertSame($this->connectionMock, CreateUsersTable_20230101000000::$postUpConnection, "Connection object should be passed to postUp().");
    }

    // Helper to create migration file for classes defined within this test file
    private function createDummyMigrationFileForPredefinedClass(string $name, string $className, string $namespace = 'CoralORM\\Migration\\Database'): void
    {
        // The class definition is already at the top of this test file.
        // We just need to create a file that Migrator can find to 'require_once'.
        // The actual class (CreateUsersTable_20230101000000) will be used due to its definition in this file.
        $content = <<<PHP
<?php
// This file is required by Migrator to simulate finding the migration.
// The actual class definition for {$namespace}\\{$className} is within the Test Case itself.
// Make sure the namespace matches what Migrator expects.
namespace {$namespace};
// class {$className} extends \CoralORM\Migration\AbstractMigration { /* ... already defined ... */ }
PHP;
        file_put_contents($this->migrationsPath . '/' . $name . '.php', $content);
    }


    // TODO: Test runDown similarly
    // TODO: Test batching logic for rollback if time permits
}
