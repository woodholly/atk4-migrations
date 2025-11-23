<?php

declare(strict_types=1);

namespace Atk4\Migrations\Tests;

use PHPUnit\Framework\TestCase;

/**
 * CLI Integration Test - runs actual CLI commands like users do.
 */
class CliIntegrationTest extends TestCase
{
    private string $testDir;
    private string $dbFile;
    private string $configFile;
    private string $migrationsDir;
    private string $cliPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test directory structure
        $this->testDir = sys_get_temp_dir() . '/atk4-cli-test-' . uniqid();
        mkdir($this->testDir);

        $this->migrationsDir = $this->testDir . '/migrations';
        mkdir($this->migrationsDir);

        $this->dbFile = $this->testDir . '/test.db';
        $this->configFile = $this->testDir . '/migrations.php';
        $this->cliPath = dirname(__DIR__) . '/migrations-cli.php';

        // Create initial config with User model
        $this->createConfig([
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Cleanup
        if (is_dir($this->testDir)) {
            $this->recursiveDelete($this->testDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createConfig(array $fields): void
    {
        $fieldsCode = '';
        foreach ($fields as $name => $options) {
            $optionsPhp = var_export($options, true);
            $fieldsCode .= "        \$this->addField('{$name}', {$optionsPhp});\n";
        }

        // Create model file
        $modelFile = $this->testDir . '/TestUserModel.php';
        $modelCode = <<<PHP
            <?php

            use Atk4\\Data\\Model;

            class TestUserModel extends Model
            {
                public \$table = 'user';

                protected function init(): void
                {
                    parent::init();
            {$fieldsCode}    }
            }
            PHP;
        file_put_contents($modelFile, $modelCode);

        // Create config that requires the model file
        $dbPath = $this->dbFile;
        $migrationsPath = $this->migrationsDir;

        $config = <<<PHP
            <?php

            require_once __DIR__ . '/TestUserModel.php';

            use Atk4\\Data\\Persistence;

            return [
                'persistence' => function () {
                    return new Persistence\\Sql('sqlite:{$dbPath}');
                },
                'models' => [TestUserModel::class],
                'migrations_paths' => ['Tests' => '{$migrationsPath}'],
                'table_storage' => [
                    'table_name' => 'doctrine_migration_versions',
                ],
            ];
            PHP;

        file_put_contents($this->configFile, $config);
    }

    /**
     * @return array{output: string, exit_code: int}
     */
    private function runCli(string $command): array
    {
        // Change to the test directory so migrations-cli.php can find migrations.php
        $originalDir = getcwd();
        chdir($this->testDir);

        $fullCommand = sprintf(
            'php %s %s 2>&1',
            escapeshellarg($this->cliPath),
            $command
        );

        exec($fullCommand, $output, $exitCode);

        chdir($originalDir);

        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @return list<string>
     */
    private function getMigrationFiles(): array
    {
        $files = glob($this->migrationsDir . '/Version*.php');

        return $files ?: [];
    }

    public function testCompleteWorkflow(): void
    {
        // 1. Initial diff should generate migration
        $result = $this->runCli('diff');

        self::assertSame(0, $result['exit_code'], 'diff command should succeed. Output: ' . $result['output']);

        $migrations = $this->getMigrationFiles();
        self::assertCount(1, $migrations, 'Should generate one migration file');

        // 2. Check status - should show 1 new migration
        $result = $this->runCli('status');

        self::assertSame(0, $result['exit_code']);
        self::assertStringContainsString('Tests\Version', $result['output']);

        // 3. Migrate - apply the migration
        $result = $this->runCli('migrate --no-interaction');

        self::assertSame(0, $result['exit_code'], 'migrate command should succeed');
        self::assertStringContainsString('Migrating', $result['output']);

        // 4. Verify table exists in database
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user'")->fetchAll();
        self::assertCount(1, $tables, 'user table should exist');

        // Check columns
        $columns = $pdo->query('PRAGMA table_info(user)')->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        self::assertContains('name', $columnNames);
        self::assertContains('email', $columnNames);

        // 5. Check up-to-date
        $result = $this->runCli('up-to-date');

        self::assertSame(0, $result['exit_code'], 'Database should be up to date');

        // 6. Add new field to model
        $this->createConfig([
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'phone' => ['type' => 'string'], // NEW FIELD
        ]);

        // Wait 1 second so second migration gets different timestamp
        sleep(1);

        // 7. Generate second migration
        $result = $this->runCli('diff');

        self::assertSame(0, $result['exit_code']);

        $migrations = $this->getMigrationFiles();
        self::assertCount(2, $migrations, 'Should have 2 migration files now');

        // 8. Apply second migration
        $result = $this->runCli('migrate --no-interaction');

        self::assertSame(0, $result['exit_code']);

        // 9. Verify phone column added
        $columns = $pdo->query('PRAGMA table_info(user)')->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        self::assertContains('phone', $columnNames, 'phone column should be added');

        // 10. Rollback last migration
        $result = $this->runCli('migrate prev --no-interaction');

        self::assertSame(0, $result['exit_code'], 'Rollback should succeed');

        // 11. Verify phone column removed
        $columns = $pdo->query('PRAGMA table_info(user)')->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        self::assertNotContains('phone', $columnNames, 'phone column should be removed after rollback');
        self::assertContains('name', $columnNames, 'name column should still exist');
        self::assertContains('email', $columnNames, 'email column should still exist');
    }

    public function testListCommand(): void
    {
        // Generate initial migration
        $this->runCli('diff');

        // List migrations
        $result = $this->runCli('migrations:list');

        self::assertSame(0, $result['exit_code']);
        self::assertStringContainsString('Tests\Version', $result['output']);
    }

    public function testStatusBeforeAnyMigrations(): void
    {
        $result = $this->runCli('status');

        self::assertSame(0, $result['exit_code']);
    }
}
