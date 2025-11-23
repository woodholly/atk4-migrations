<?php

declare(strict_types=1);

namespace Atk4\Migrations\Tests;

use Atk4\Data\Exception;
use Atk4\Data\Model as BaseModel;
use Atk4\Data\Persistence;
use Atk4\Data\Schema\Migrator;
use Atk4\Migrations\Atk4SchemaProvider;
use Atk4\Migrations\Model;
use PHPUnit\Framework\TestCase;

/**
 * Test Model index functionality.
 *
 * Shows how users can add indexes to their models using Atk4\Migrations\Model.
 */
class IndexableModelTest extends TestCase
{
    private Persistence\Sql $persistence;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbFile = sys_get_temp_dir() . '/indexable-test-' . uniqid() . '.db';
        $this->persistence = new Persistence\Sql('sqlite:' . $this->dbFile);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testSingleFieldIndex(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('email');
                $this->addField('username');

                // Add non-unique index on email
                $this->addIndex('email');
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('user');

        // Verify index exists
        $indexes = $table->getIndexes();

        // Filter out primary key
        $indexes = array_filter($indexes, static fn ($idx) => !$idx->isPrimary());

        self::assertCount(1, $indexes);

        $index = reset($indexes);
        self::assertSame(['email'], $index->getUnquotedColumns());
        self::assertFalse($index->isUnique());
    }

    public function testUniqueIndex(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('email');

                // Add unique index on email
                $this->addIndex('email', ['unique' => true]);
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('user');

        // Verify unique index exists
        $indexes = $table->getIndexes();
        $indexes = array_filter($indexes, static fn ($idx) => !$idx->isPrimary());

        self::assertCount(1, $indexes);

        $index = reset($indexes);
        self::assertSame(['email'], $index->getUnquotedColumns());
        self::assertTrue($index->isUnique());
    }

    public function testMultiFieldIndex(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('first_name');
                $this->addField('last_name');

                // Add composite index
                $this->addIndex(['first_name', 'last_name']);
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('user');

        // Verify multi-field index exists
        $indexes = $table->getIndexes();
        $indexes = array_filter($indexes, static fn ($idx) => !$idx->isPrimary());

        self::assertCount(1, $indexes);

        $index = reset($indexes);
        self::assertSame(['first_name', 'last_name'], $index->getUnquotedColumns());
        self::assertFalse($index->isUnique());
    }

    public function testMultipleIndexes(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('email');
                $this->addField('username');
                $this->addField('country');

                // Add multiple indexes
                $this->addIndex('email', ['unique' => true]);
                $this->addIndex('username', ['unique' => true]);
                $this->addIndex('country'); // non-unique
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('user');

        // Verify all indexes exist
        $indexes = $table->getIndexes();
        $indexes = array_filter($indexes, static fn ($idx) => !$idx->isPrimary());

        self::assertCount(3, $indexes);
    }

    public function testNamedIndex(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('email');

                // Add index with custom name
                $this->addIndex('email', ['name' => 'custom_email_idx']);
            }
        };

        // Verify index stored with custom name
        self::assertTrue($model->hasIndexes());
        $indexes = $model->getIndexes();

        self::assertArrayHasKey('custom_email_idx', $indexes);
        self::assertSame(['email'], $indexes['custom_email_idx']['fields']);
    }

    public function testIndexCreatedInDatabase(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('email');
                $this->addField('username');

                $this->addIndex('email', ['unique' => true]);
                $this->addIndex('username');
            }
        };

        // Create table with indexes
        (new Migrator($model))->create();

        // Manually add indexes using schema provider
        $provider = new Atk4SchemaProvider([$model]);
        $targetSchema = $provider->createSchema();

        $schemaManager = $this->persistence->getConnection()->createSchemaManager();

        // Apply indexes
        foreach ($targetSchema->getTable('user')->getIndexes() as $index) {
            if (!$index->isPrimary()) {
                $schemaManager->createIndex($index, 'user');
            }
        }

        // Verify indexes exist in database
        $tableIndexes = $schemaManager->listTableIndexes('user');

        // Remove primary key from list
        unset($tableIndexes['primary']);

        self::assertGreaterThanOrEqual(2, count($tableIndexes));
    }

    public function testModelWithoutIndexSupport(): void
    {
        // Regular model without Atk4\Migrations\Model should work fine
        $model = new class($this->persistence) extends BaseModel {
            public $table = 'simple';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
            }
        };

        // Generate schema - should not fail
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('simple');

        // Only primary key should exist
        $indexes = $table->getIndexes();
        self::assertCount(1, $indexes); // Just the primary key
    }

    public function testIndexRemovalDetection(): void
    {
        // Create model with two indexes
        $modelWithIndexes = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('email');
                $this->addField('username');

                $this->addIndex('email', ['unique' => true]);
                $this->addIndex('username');
            }
        };

        // Create table and indexes
        (new Migrator($modelWithIndexes))->create();
        $provider = new Atk4SchemaProvider([$modelWithIndexes]);
        $targetSchema = $provider->createSchema();

        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        foreach ($targetSchema->getTable('user')->getIndexes() as $index) {
            if (!$index->isPrimary()) {
                $schemaManager->createIndex($index, 'user');
            }
        }

        // Verify both indexes exist
        $dbIndexes = $schemaManager->listTableIndexes('user');
        unset($dbIndexes['primary']);
        self::assertCount(2, $dbIndexes);

        // Create model with only one index (email removed)
        $modelWithoutEmailIndex = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('email');
                $this->addField('username');

                // Only username index - email index removed!
                $this->addIndex('username');
            }
        };

        // Generate new schema and compare
        $provider = new Atk4SchemaProvider([$modelWithoutEmailIndex]);
        $newTargetSchema = $provider->createSchema();

        $currentSchema = $schemaManager->createSchema();
        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($currentSchema, $newTargetSchema);

        // Verify that schema diff detected changes
        self::assertNotEmpty(
            $schemaDiff->getAlteredTables(),
            'Schema diff should detect index removal'
        );

        // Verify new schema only has username index
        $newTableIndexes = $newTargetSchema->getTable('user')->getIndexes();
        $newTableIndexes = array_filter($newTableIndexes, static fn ($idx) => !$idx->isPrimary());
        self::assertCount(1, $newTableIndexes, 'New schema should have only 1 index');

        $remainingIndex = reset($newTableIndexes);
        self::assertSame(['username'], $remainingIndex->getUnquotedColumns());
    }

    // Tests for addField() override with index support

    public function testRegularIndexViaAddField(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();

                // Regular index using 'index' => true in addField()
                $this->addField('email', ['index' => true]);
            }
        };

        // Verify index was created
        self::assertTrue($model->hasIndexes());
        $indexes = $model->getIndexes();
        self::assertCount(1, $indexes);

        $index = reset($indexes);
        self::assertSame(['email'], $index['fields']);
        self::assertFalse($index['unique']);
    }

    public function testUniqueIndexViaIndexOption(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();

                // Unique index using 'index' => 'unique' in addField()
                $this->addField('username', ['index' => 'unique']);
            }
        };

        // Verify unique index was created
        self::assertTrue($model->hasIndexes());
        $indexes = $model->getIndexes();
        self::assertCount(1, $indexes);

        $index = reset($indexes);
        self::assertSame(['username'], $index['fields']);
        self::assertTrue($index['unique']);
    }

    public function testUniqueIndexViaUniqueOption(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();

                // Unique index using 'unique' => true in addField()
                $this->addField('email', ['unique' => true]);
            }
        };

        // Verify unique index was created
        self::assertTrue($model->hasIndexes());
        $indexes = $model->getIndexes();
        self::assertCount(1, $indexes);

        $index = reset($indexes);
        self::assertSame(['email'], $index['fields']);
        self::assertTrue($index['unique']);
    }

    public function testMultipleFieldsWithIndexesViaAddField(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();

                $this->addField('name'); // No index
                $this->addField('email', ['unique' => true]);
                $this->addField('country', ['index' => true]);
                $this->addField('username', ['index' => 'unique']);
            }
        };

        // Verify multiple indexes
        $indexes = $model->getIndexes();
        self::assertCount(3, $indexes); // email (unique), country, username (unique)

        // Check each index
        $indexesByField = [];
        foreach ($indexes as $index) {
            $field = $index['fields'][0];
            $indexesByField[$field] = $index['unique'];
        }

        self::assertTrue($indexesByField['email']);
        self::assertFalse($indexesByField['country']);
        self::assertTrue($indexesByField['username']);
    }

    public function testInvalidIndexOptionThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid index option');

        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();

                // Invalid index value
                $this->addField('name', ['index' => 'invalid']);
            }
        };
    }

    public function testFieldOptionsNotAffected(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();

                // Field with regular options plus index
                $this->addField('email', [
                    'type' => 'string',
                    'unique' => true,
                ]);
            }
        };

        // Verify field exists with correct properties
        self::assertTrue($model->hasField('email'));
        $field = $model->getField('email');

        // Verify the field was created and index was added
        self::assertTrue($model->hasIndexes());
    }

    public function testAddFieldIndexIntegrationWithSchema(): void
    {
        // User model using addField() with index options
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
                $this->addField('email', ['unique' => true]);
                $this->addField('country', ['index' => true]);
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();

        // Verify table has indexes
        $table = $schema->getTable('user');
        $indexes = array_filter(
            $table->getIndexes(),
            static fn ($idx) => !$idx->isPrimary()
        );

        self::assertCount(2, $indexes); // email (unique), country

        // Verify index properties
        $indexColumns = [];
        $indexUnique = [];
        foreach ($indexes as $index) {
            $cols = $index->getUnquotedColumns();
            $indexColumns[] = $cols[0];
            $indexUnique[$cols[0]] = $index->isUnique();
        }

        self::assertContains('email', $indexColumns);
        self::assertContains('country', $indexColumns);
        self::assertTrue($indexUnique['email']);
        self::assertFalse($indexUnique['country']);
    }

    public function testIndexWorksWithHasOne(): void
    {
        // Test that index support works with hasOne relationships
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                // Index in hasOne()
                $persistence = $this->getPersistence();
                $this->hasOne('user_id', [
                    'model' => static function () use ($persistence) {
                        return new class($persistence) extends BaseModel {
                            public $table = 'user';

                            protected function init(): void
                            {
                                parent::init();
                                $this->addField('name');
                            }
                        };
                    },
                    'index' => true,
                ]);

                // Unique index in hasOne()
                $this->hasOne('category_id', [
                    'model' => static function () use ($persistence) {
                        return new class($persistence) extends BaseModel {
                            public $table = 'category';

                            protected function init(): void
                            {
                                parent::init();
                                $this->addField('name');
                            }
                        };
                    },
                    'unique' => true,
                ]);
            }
        };

        // Verify indexes were created
        self::assertTrue($model->hasIndexes());
        $indexes = $model->getIndexes();
        self::assertCount(2, $indexes);

        // Check index properties
        $indexesByField = [];
        foreach ($indexes as $index) {
            $field = $index['fields'][0];
            $indexesByField[$field] = $index['unique'];
        }

        self::assertArrayHasKey('user_id', $indexesByField);
        self::assertArrayHasKey('category_id', $indexesByField);
        self::assertFalse($indexesByField['user_id']); // Regular index
        self::assertTrue($indexesByField['category_id']); // Unique index
    }

    public function testModelWorksWithExpressionFields(): void
    {
        // Test that our Model works with addExpression() which passes Field objects
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('first_name');
                $this->addField('last_name');

                // addExpression passes a Field object to addField() internally
                $this->addExpression('full_name', ['expr' => '[first_name] || " " || [last_name]']);
            }
        };

        // Verify model was created without errors
        self::assertTrue($model->hasField('first_name'));
        self::assertTrue($model->hasField('last_name'));
        self::assertTrue($model->hasField('full_name'));

        // Generate schema - should not fail
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('user');

        // Expression fields should not create database columns
        self::assertTrue($table->hasColumn('first_name'));
        self::assertTrue($table->hasColumn('last_name'));
        self::assertFalse($table->hasColumn('full_name'), 'Expression field should not create database column');
    }
}
