<?php

declare(strict_types=1);

namespace Atk4\Migrations\Tests;

use Atk4\Data\Model;
use Atk4\Data\Persistence;
use Atk4\Data\Schema\Migrator;
use Atk4\Migrations\Atk4SchemaProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration test showing the complete migration workflow.
 */
class IntegrationTest extends TestCase
{
    private Persistence\Sql $persistence;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbFile = sys_get_temp_dir() . '/integration-test-' . uniqid() . '.db';
        $this->persistence = new Persistence\Sql('sqlite:' . $this->dbFile);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testCompleteWorkflow(): void
    {
        // 1. Define initial model
        $userModel = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
                $this->addField('email');
            }
        };

        // 2. Create initial table
        $migration = new Migrator($userModel);
        $migration->create();

        // Verify table exists
        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        self::assertTrue($schemaManager->tablesExist(['user']));

        // 3. Simulate model change - add new field
        $updatedUserModel = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
                $this->addField('email');
                $this->addField('phone'); // NEW FIELD
            }
        };

        // 4. Generate schema diff using our SchemaProvider
        $provider = new Atk4SchemaProvider([$updatedUserModel]);
        $targetSchema = $provider->createSchema();

        // Get current database schema
        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        $currentSchema = $schemaManager->createSchema();

        // Compare schemas
        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($currentSchema, $targetSchema);

        // Get migration SQL
        $platform = $this->persistence->getConnection()->getDatabasePlatform();
        $migrationSqls = $schemaDiff->toSql($platform);

        // Verify SQL is generated for adding the phone column
        self::assertNotEmpty($migrationSqls, 'Migration SQL should be generated');
        $allSql = strtoupper(implode(' ', $migrationSqls));
        // SQLite may use TEMP table recreation or ADD COLUMN depending on version
        self::assertTrue(
            str_contains($allSql, 'ADD COLUMN') || str_contains($allSql, 'PHONE'),
            'Migration should reference the phone column'
        );

        // 5. Execute migration SQL
        $conn = $this->persistence->getConnection();
        foreach ($migrationSqls as $sql) {
            $conn->executeStatement($conn->expr($sql));
        }

        // 6. Verify new field exists
        $columns = $schemaManager->listTableColumns('user');
        $columnNames = array_map(static fn ($col) => $col->getName(), $columns);

        self::assertContains('phone', $columnNames);
        self::assertContains('name', $columnNames);
        self::assertContains('email', $columnNames);
    }

    public function testDetectDroppedField(): void
    {
        // Create table with 3 fields
        $initialModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('content', ['type' => 'text']);
                $this->addField('draft', ['type' => 'boolean']);
            }
        };

        (new Migrator($initialModel))->create();

        // Model with field removed
        $updatedModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('content', ['type' => 'text']);
                // 'draft' field removed
            }
        };

        // Generate diff
        $provider = new Atk4SchemaProvider([$updatedModel]);
        $targetSchema = $provider->createSchema();

        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        $currentSchema = $schemaManager->createSchema();

        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($currentSchema, $targetSchema);

        $platform = $this->persistence->getConnection()->getDatabasePlatform();
        $migrationSqls = $schemaDiff->toSql($platform);

        // Verify migration SQL is generated (SQLite recreates table instead of DROP COLUMN)
        self::assertNotEmpty($migrationSqls);

        // Execute migration
        $conn = $this->persistence->getConnection();
        foreach ($migrationSqls as $sql) {
            $conn->executeStatement($conn->expr($sql));
        }

        // Verify 'draft' field no longer exists
        $columns = $schemaManager->listTableColumns('post');
        $columnNames = array_map(static fn ($col) => $col->getName(), $columns);

        self::assertNotContains('draft', $columnNames);
        self::assertContains('title', $columnNames);
        self::assertContains('content', $columnNames);
    }
}
