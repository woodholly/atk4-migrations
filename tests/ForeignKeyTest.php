<?php

declare(strict_types=1);

namespace Atk4\Migrations\Tests;

use Atk4\Data\Exception;
use Atk4\Data\Model as BaseModel;
use Atk4\Data\Persistence;
use Atk4\Data\Schema\Migrator;
use Atk4\Migrations\Atk4SchemaProvider;
use Atk4\Migrations\Model;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;

/**
 * Tests for foreign key constraint support.
 *
 * ATK4 doesn't create foreign keys automatically, but Doctrine DBAL supports them.
 * These tests verify that foreign keys CAN be added manually and detected.
 */
class ForeignKeyTest extends TestCase
{
    private Persistence\Sql $persistence;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbFile = sys_get_temp_dir() . '/fk-test-' . uniqid() . '.db';
        $this->persistence = new Persistence\Sql('sqlite:' . $this->dbFile);

        // Enable foreign keys in SQLite (they're off by default)
        $conn = $this->persistence->getConnection();
        $conn->executeStatement($conn->expr('PRAGMA foreign_keys = ON'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testManuallyAddedForeignKeyIsDetected(): void
    {
        // 1. Create user table
        $userModel = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
            }
        };
        (new Migrator($userModel))->create();

        // 2. Create post table WITHOUT foreign key
        $postModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('user_id', ['type' => 'integer']);
            }
        };
        (new Migrator($postModel))->create();

        // 3. Manually add foreign key constraint using raw SQL
        $conn = $this->persistence->getConnection();
        $conn->executeStatement($conn->expr('
            CREATE TABLE post_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                user_id INTEGER,
                FOREIGN KEY (user_id) REFERENCES user(id)
            )
        '));
        $conn->executeStatement($conn->expr('INSERT INTO post_new SELECT * FROM post'));
        $conn->executeStatement($conn->expr('DROP TABLE post'));
        $conn->executeStatement($conn->expr('ALTER TABLE post_new RENAME TO post'));

        // 4. Verify foreign key exists
        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        $table = $schemaManager->introspectTable('post');
        $foreignKeys = $table->getForeignKeys();

        self::assertCount(1, $foreignKeys, 'Foreign key should exist after manual creation');

        $fk = reset($foreignKeys);
        self::assertSame('user', $fk->getForeignTableName());
        self::assertSame(['user_id'], $fk->getUnquotedLocalColumns());
        self::assertSame(['id'], $fk->getUnquotedForeignColumns());
    }

    public function testForeignKeyDifferenceIsDetected(): void
    {
        // Create tables with foreign key
        $connection = $this->persistence->getConnection();

        $connection->executeStatement($connection->expr('
            CREATE TABLE user (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            )
        '));

        $connection->executeStatement($connection->expr('
            CREATE TABLE post (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                user_id INTEGER,
                FOREIGN KEY (user_id) REFERENCES user(id)
            )
        '));

        // Get current schema (with foreign key)
        $schemaManager = $connection->createSchemaManager();
        $currentSchema = $schemaManager->createSchema();

        // Create target schema WITHOUT foreign key
        $postModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('user_id', ['type' => 'integer']);
            }
        };

        $provider = new Atk4SchemaProvider([$postModel]);
        $targetSchema = $provider->createSchema();

        // Compare schemas
        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($currentSchema, $targetSchema);

        // Check if differences were detected
        self::assertNotEmpty(
            $schemaDiff->getAlteredTables() || $schemaDiff->getDroppedTables() || $schemaDiff->getCreatedTables(),
            'Should detect schema difference when foreign key is present/absent'
        );
    }

    public function testManuallyCreatedForeignKeyInSchema(): void
    {
        // This test shows how to manually add foreign keys to schema
        // even though ATK4 doesn't do it automatically

        $platform = $this->persistence->getConnection()->getDatabasePlatform();

        // Create user table manually
        $userTable = new Table($platform->quoteSingleIdentifier('user'));
        $userTable->addColumn($platform->quoteSingleIdentifier('id'), 'integer')->setAutoincrement(true);
        $userTable->addColumn($platform->quoteSingleIdentifier('name'), 'string');
        $userTable->setPrimaryKey([$platform->quoteSingleIdentifier('id')]);

        // Create post table with foreign key manually
        $postTable = new Table($platform->quoteSingleIdentifier('post'));
        $postTable->addColumn($platform->quoteSingleIdentifier('id'), 'integer')->setAutoincrement(true);
        $postTable->addColumn($platform->quoteSingleIdentifier('title'), 'string');
        $postTable->addColumn($platform->quoteSingleIdentifier('user_id'), 'integer')->setUnsigned(true);
        $postTable->setPrimaryKey([$platform->quoteSingleIdentifier('id')]);

        // Manually add foreign key constraint
        $postTable->addForeignKeyConstraint(
            $userTable,
            [$platform->quoteSingleIdentifier('user_id')],
            [$platform->quoteSingleIdentifier('id')],
            ['onDelete' => 'CASCADE']
        );

        // Create tables
        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        $schemaManager->createTable($userTable);
        $schemaManager->createTable($postTable);

        // Verify foreign key was created
        $table = $schemaManager->introspectTable('post');
        $foreignKeys = $table->getForeignKeys();

        self::assertCount(1, $foreignKeys, 'Manually created foreign key should exist');

        $fk = reset($foreignKeys);
        self::assertSame('user', $fk->getForeignTableName());
        self::assertSame(['user_id'], $fk->getUnquotedLocalColumns());
        self::assertSame(['id'], $fk->getUnquotedForeignColumns());
        self::assertSame('CASCADE', $fk->getOption('onDelete'));
    }

    // ========================================================================
    // Foreign Key Tests
    // ========================================================================

    public function testSingleColumnForeignKeyExplicit(): void
    {
        // Create model with explicit FK definition
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('author_id', ['type' => 'integer']);

                // Explicitly define FK
                $this->addForeignKey('author_id', [
                    'foreignTable' => 'user',
                    'foreignColumn' => 'id',
                    'onDelete' => 'CASCADE',
                ]);
            }
        };

        // Verify FK is stored
        self::assertTrue($model->hasForeignKeys());
        $fks = $model->getForeignKeys();
        self::assertCount(1, $fks);

        $fk = reset($fks);
        self::assertSame(['author_id'], $fk['localColumns']);
        self::assertSame('user', $fk['foreignTable']);
        self::assertSame(['id'], $fk['foreignColumns']);
        self::assertSame('CASCADE', $fk['onDelete']);
        self::assertNull($fk['onUpdate']);
    }

    public function testSingleColumnForeignKeyWithDefaultForeignColumn(): void
    {
        // Test that single column FK defaults to 'id' for foreign column
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('user_id', ['type' => 'integer']);

                // Don't specify foreignColumn - should default to 'id'
                $this->addForeignKey('user_id', [
                    'foreignTable' => 'user',
                    'onDelete' => 'CASCADE',
                ]);
            }
        };

        // Verify FK uses default 'id' for foreign column
        self::assertTrue($model->hasForeignKeys());
        $fks = $model->getForeignKeys();
        self::assertCount(1, $fks);

        $fk = reset($fks);
        self::assertSame(['user_id'], $fk['localColumns']);
        self::assertSame('user', $fk['foreignTable']);
        self::assertSame(['id'], $fk['foreignColumns']); // Default to 'id'
        self::assertSame('CASCADE', $fk['onDelete']);
    }

    public function testCompositeForeignKey(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'order_items';

            protected function init(): void
            {
                parent::init();
                $this->addField('product_id', ['type' => 'integer']);
                $this->addField('warehouse_id', ['type' => 'integer']);
                $this->addField('quantity', ['type' => 'integer']);

                // Composite FK referencing (product_id, warehouse_id) in inventory table
                $this->addForeignKey(['product_id', 'warehouse_id'], [
                    'foreignTable' => 'inventory',
                    'foreignColumns' => ['product_id', 'warehouse_id'],
                    'onDelete' => 'RESTRICT',
                    'onUpdate' => 'CASCADE',
                ]);
            }
        };

        // Verify composite FK
        self::assertTrue($model->hasForeignKeys());
        $fks = $model->getForeignKeys();
        self::assertCount(1, $fks);

        $fk = reset($fks);
        self::assertSame(['product_id', 'warehouse_id'], $fk['localColumns']);
        self::assertSame('inventory', $fk['foreignTable']);
        self::assertSame(['product_id', 'warehouse_id'], $fk['foreignColumns']);
        self::assertSame('RESTRICT', $fk['onDelete']);
        self::assertSame('CASCADE', $fk['onUpdate']);
    }

    public function testMultipleForeignKeys(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('author_id', ['type' => 'integer']);
                $this->addField('category_id', ['type' => 'integer']);

                // Multiple FKs
                $this->addForeignKey('author_id', [
                    'foreignTable' => 'user',
                    'onDelete' => 'CASCADE',
                ]);

                $this->addForeignKey('category_id', [
                    'foreignTable' => 'category',
                    'onDelete' => 'SET NULL',
                ]);
            }
        };

        // Verify both FKs stored
        self::assertTrue($model->hasForeignKeys());
        $fks = $model->getForeignKeys();
        self::assertCount(2, $fks);

        // Verify FK names contain table and column info
        $fkNames = array_keys($fks);
        self::assertContains('fk_post_author_id_user', $fkNames);
        self::assertContains('fk_post_category_id_category', $fkNames);
    }

    public function testForeignKeyWithAllReferentialActions(): void
    {
        $validActions = ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];

        foreach ($validActions as $action) {
            $model = new class($this->persistence) extends Model {
                public $table = 'test';
                /** @var string */
                public static $testAction;

                protected function init(): void
                {
                    parent::init();
                    $this->addField('ref_id', ['type' => 'integer']);

                    $this->addForeignKey('ref_id', [
                        'foreignTable' => 'parent',
                        'onDelete' => self::$testAction,
                        'onUpdate' => self::$testAction,
                    ]);
                }
            };

            $model::$testAction = $action;
            $model = new $model($this->persistence);

            $fks = $model->getForeignKeys();
            $fk = reset($fks);

            self::assertSame($action, $fk['onDelete'], "onDelete should be {$action}");
            self::assertSame($action, $fk['onUpdate'], "onUpdate should be {$action}");
        }
    }

    public function testForeignKeyInvalidActionThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid onDelete action');

        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();
                $this->addField('ref_id', ['type' => 'integer']);

                $this->addForeignKey('ref_id', [
                    'foreignTable' => 'parent',
                    'onDelete' => 'INVALID_ACTION',
                ]);
            }
        };
    }

    public function testForeignKeyEmptyColumnsThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('at least one local column');

        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();

                // Empty array should throw exception
                $this->addForeignKey([], ['foreignTable' => 'parent']);
            }
        };
    }

    public function testForeignKeyMismatchedColumnCountThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('must match number of foreign columns');

        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();
                $this->addField('col1', ['type' => 'integer']);
                $this->addField('col2', ['type' => 'integer']);

                // 2 local columns but 1 foreign column - should fail
                $this->addForeignKey(['col1', 'col2'], [
                    'foreignTable' => 'parent',
                    'foreignColumns' => ['id'], // Mismatch!
                ]);
            }
        };
    }

    public function testForeignKeyMissingTableThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Foreign table must be specified');

        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();
                $this->addField('ref_id', ['type' => 'integer']);

                // No foreignTable and no hasOne reference to auto-detect
                $this->addForeignKey('ref_id', ['onDelete' => 'CASCADE']);
            }
        };
    }

    public function testForeignKeyIntegrationWithSchemaProvider(): void
    {
        // Create User model
        $userModel = new class($this->persistence) extends BaseModel {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
            }
        };

        // Create Post model with FK
        $postModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('user_id', ['type' => 'integer']);

                $this->addForeignKey('user_id', [
                    'foreignTable' => 'user',
                    'foreignColumn' => 'id',
                    'onDelete' => 'CASCADE',
                ]);
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$userModel, $postModel]);
        $schema = $provider->createSchema();

        // Verify FK was added to schema
        $postTable = $schema->getTable('post');
        $foreignKeys = $postTable->getForeignKeys();

        self::assertCount(1, $foreignKeys, 'Schema should have FK from trait');

        $fk = reset($foreignKeys);
        self::assertSame('user', $fk->getForeignTableName());
        self::assertSame(['user_id'], $fk->getUnquotedLocalColumns());
        self::assertSame(['id'], $fk->getUnquotedForeignColumns());
        self::assertSame('CASCADE', $fk->getOption('onDelete'));
    }

    public function testCompositeForeignKeyIntegrationWithSchemaProvider(): void
    {
        // Create Inventory model
        $inventoryModel = new class($this->persistence) extends BaseModel {
            public $table = 'inventory';

            protected function init(): void
            {
                parent::init();
                $this->addField('product_id', ['type' => 'integer']);
                $this->addField('warehouse_id', ['type' => 'integer']);
                $this->addField('stock', ['type' => 'integer']);
            }
        };

        // Create OrderItems model with composite FK
        $orderItemsModel = new class($this->persistence) extends Model {
            public $table = 'order_items';

            protected function init(): void
            {
                parent::init();
                $this->addField('product_id', ['type' => 'integer']);
                $this->addField('warehouse_id', ['type' => 'integer']);
                $this->addField('quantity', ['type' => 'integer']);

                $this->addForeignKey(['product_id', 'warehouse_id'], [
                    'foreignTable' => 'inventory',
                    'foreignColumns' => ['product_id', 'warehouse_id'],
                    'onDelete' => 'RESTRICT',
                ]);
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$inventoryModel, $orderItemsModel]);
        $schema = $provider->createSchema();

        // Verify composite FK in schema
        $orderItemsTable = $schema->getTable('order_items');
        $foreignKeys = $orderItemsTable->getForeignKeys();

        self::assertCount(1, $foreignKeys, 'Schema should have composite FK');

        $fk = reset($foreignKeys);
        self::assertSame('inventory', $fk->getForeignTableName());
        self::assertSame(['product_id', 'warehouse_id'], $fk->getUnquotedLocalColumns());
        self::assertSame(['product_id', 'warehouse_id'], $fk->getUnquotedForeignColumns());
        self::assertSame('RESTRICT', $fk->getOption('onDelete'));
    }

    public function testCustomForeignKeyName(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('author_id', ['type' => 'integer']);

                $this->addForeignKey('author_id', [
                    'foreignTable' => 'user',
                    'name' => 'custom_fk_name',
                ]);
            }
        };

        $fks = $model->getForeignKeys();
        self::assertArrayHasKey('custom_fk_name', $fks);
    }

    public function testModelWithoutForeignKeySupport(): void
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

        // No foreign keys should exist
        $foreignKeys = $table->getForeignKeys();
        self::assertEmpty($foreignKeys);
    }

    // ========================================================================
    // hasOne() Foreign Key Integration Tests
    // ========================================================================

    public function testHasOneWithSimpleForeignKey(): void
    {
        // Create model with FK defined in hasOne()
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                // Simple FK: flat syntax (ODOO-like)
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
                    'onDelete' => 'CASCADE',
                ]);
            }
        };

        // Verify FK was created
        self::assertTrue($model->hasForeignKeys());
        $foreignKeys = $model->getForeignKeys();
        self::assertCount(1, $foreignKeys);

        $fk = reset($foreignKeys);
        self::assertSame(['user_id'], $fk['localColumns']);
        self::assertSame('user', $fk['foreignTable']);
        self::assertSame(['id'], $fk['foreignColumns']);
        self::assertSame('CASCADE', $fk['onDelete']);
    }

    public function testHasOneWithOnlyOnUpdate(): void
    {
        // Test with only onUpdate (no onDelete)
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                // FK with only onUpdate
                $persistence = $this->getPersistence();
                $this->hasOne('author_id', [
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
                    'onUpdate' => 'CASCADE',
                ]);
            }
        };

        // Verify FK
        self::assertTrue($model->hasForeignKeys());
        $foreignKeys = $model->getForeignKeys();
        self::assertCount(1, $foreignKeys);

        $fk = reset($foreignKeys);
        self::assertSame(['author_id'], $fk['localColumns']);
        self::assertSame('user', $fk['foreignTable']);
        self::assertSame(['id'], $fk['foreignColumns']);
        self::assertNull($fk['onDelete']);
        self::assertSame('CASCADE', $fk['onUpdate']);
    }

    public function testHasOneWithBothOnDeleteAndOnUpdate(): void
    {
        // Test flat syntax with both onDelete and onUpdate
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                $persistence = $this->getPersistence();
                $this->hasOne('author_id', [
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
                    'onDelete' => 'CASCADE',
                    'onUpdate' => 'CASCADE',
                ]);
            }
        };

        // Verify FK with all options
        $foreignKeys = $model->getForeignKeys();
        $fk = reset($foreignKeys);

        self::assertSame(['author_id'], $fk['localColumns']);
        self::assertSame('user', $fk['foreignTable']);
        self::assertSame(['id'], $fk['foreignColumns']);
        self::assertSame('CASCADE', $fk['onDelete']);
        self::assertSame('CASCADE', $fk['onUpdate']);
    }

    public function testHasOneWithSetNull(): void
    {
        // Test flat syntax with SET NULL
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                $persistence = $this->getPersistence();
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
                    'onDelete' => 'SET NULL',
                ]);
            }
        };

        // Verify FK
        $foreignKeys = $model->getForeignKeys();
        $fk = reset($foreignKeys);

        self::assertSame(['category_id'], $fk['localColumns']);
        self::assertSame('category', $fk['foreignTable']);
        self::assertSame(['id'], $fk['foreignColumns']);
        self::assertSame('SET NULL', $fk['onDelete']);
    }

    public function testHasOneWithMultipleForeignKeys(): void
    {
        // Test model with multiple hasOne relationships with FKs (flat syntax)
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                $persistence = $this->getPersistence();
                $this->hasOne('author_id', [
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
                    'onDelete' => 'CASCADE',
                ]);

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
                    'onDelete' => 'SET NULL',
                ]);
            }
        };

        // Verify both FKs were created
        self::assertTrue($model->hasForeignKeys());
        $foreignKeys = $model->getForeignKeys();
        self::assertCount(2, $foreignKeys);

        // Verify FK names
        $fkNames = array_keys($foreignKeys);
        self::assertContains('fk_post_author_id_user', $fkNames);
        self::assertContains('fk_post_category_id_category', $fkNames);
    }

    public function testHasOneWithInvalidOnDeleteThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid onDelete action');

        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();

                // Invalid onDelete action
                $persistence = $this->getPersistence();
                $this->hasOne('ref_id', [
                    'model' => static function () use ($persistence) {
                        return new class($persistence) extends BaseModel {
                            public $table = 'other';

                            protected function init(): void
                            {
                                parent::init();
                                $this->addField('name');
                            }
                        };
                    },
                    'onDelete' => 'INVALID_ACTION',
                ]);
            }
        };
    }

    public function testHasOneWithForeignKeyIntegrationWithSchema(): void
    {
        // User model
        $userModel = new class($this->persistence) extends BaseModel {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
            }
        };

        // Post model with FK in hasOne() (flat syntax)
        $postModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

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
                    'onDelete' => 'CASCADE',
                ]);
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$userModel, $postModel]);
        $schema = $provider->createSchema();

        // Verify FK in schema
        $postTable = $schema->getTable('post');
        $foreignKeys = $postTable->getForeignKeys();
        self::assertCount(1, $foreignKeys);

        $fk = reset($foreignKeys);
        self::assertSame('user', $fk->getForeignTableName());
        self::assertSame(['user_id'], $fk->getUnquotedLocalColumns());
        self::assertSame('CASCADE', $fk->getOption('onDelete'));
    }

    public function testHasOneWithIndexInSeed(): void
    {
        // Test that index option works in hasOne() seed (requires both traits)
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                // FK + index in one seed (flat syntax)
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
                    'onDelete' => 'CASCADE',
                    'index' => true,  // Index option in hasOne() seed
                ]);
            }
        };

        // Verify FK was created
        self::assertTrue($model->hasForeignKeys());
        $foreignKeys = $model->getForeignKeys();
        self::assertCount(1, $foreignKeys);

        $fk = reset($foreignKeys);
        self::assertSame(['user_id'], $fk['localColumns']);
        self::assertSame('CASCADE', $fk['onDelete']);

        // Verify index was created (via Model's hasOne() method)
        self::assertTrue($model->hasIndexes());
        $indexes = $model->getIndexes();
        self::assertCount(1, $indexes);

        $index = reset($indexes);
        self::assertSame(['user_id'], $index['fields']);
        self::assertFalse($index['unique']);
    }

    public function testHasOneWithUniqueIndexInSeed(): void
    {
        // Test that unique option works in hasOne() seed
        $model = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');

                // FK + unique index in one seed
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
                    'onDelete' => 'CASCADE',
                    'unique' => true,  // Unique index
                ]);
            }
        };

        // Verify both FK and unique index
        self::assertTrue($model->hasForeignKeys());
        self::assertTrue($model->hasIndexes());

        $indexes = $model->getIndexes();
        $index = reset($indexes);
        self::assertTrue($index['unique']);
    }
}
