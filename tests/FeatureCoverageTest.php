<?php

declare(strict_types=1);

namespace Atk4\Migrations\Tests;

use Atk4\Data\Model;
use Atk4\Data\Persistence;
use Atk4\Data\Schema\Migrator;
use Atk4\Migrations\Atk4SchemaProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests comprehensive ATK4 Data feature coverage for migrations.
 */
class FeatureCoverageTest extends TestCase
{
    private Persistence\Sql $persistence;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbFile = sys_get_temp_dir() . '/feature-test-' . uniqid() . '.db';
        $this->persistence = new Persistence\Sql('sqlite:' . $this->dbFile);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testAllFieldTypes(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'all_types';

            protected function init(): void
            {
                parent::init();
                $this->addField('name', ['type' => 'string']);
                $this->addField('bio', ['type' => 'text']);
                $this->addField('age', ['type' => 'integer']);
                $this->addField('salary', ['type' => 'float']);
                $this->addField('is_active', ['type' => 'boolean']);
                $this->addField('birth_date', ['type' => 'date']);
                $this->addField('created_at', ['type' => 'datetime']);
                $this->addField('start_time', ['type' => 'time']);
            }
        };

        // Generate schema
        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('all_types');

        // Verify all field types
        self::assertTrue($table->hasColumn('name'));
        self::assertTrue($table->hasColumn('bio'));
        self::assertTrue($table->hasColumn('age'));
        self::assertTrue($table->hasColumn('salary'));
        self::assertTrue($table->hasColumn('is_active'));
        self::assertTrue($table->hasColumn('birth_date'));
        self::assertTrue($table->hasColumn('created_at'));
        self::assertTrue($table->hasColumn('start_time'));

        // Verify types
        self::assertSame('string', $table->getColumn('name')->getType()->getName());
        self::assertSame('text', $table->getColumn('bio')->getType()->getName());
        self::assertContains($table->getColumn('age')->getType()->getName(), ['integer', 'bigint']);
        self::assertContains($table->getColumn('salary')->getType()->getName(), ['float', 'decimal']);
        self::assertSame('boolean', $table->getColumn('is_active')->getType()->getName());
        self::assertSame('date', $table->getColumn('birth_date')->getType()->getName());
        self::assertSame('datetime', $table->getColumn('created_at')->getType()->getName());
        self::assertSame('time', $table->getColumn('start_time')->getType()->getName());
    }

    public function testMandatoryFields(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'mandatory_test';

            protected function init(): void
            {
                parent::init();
                $this->addField('required_name', ['type' => 'string', 'required' => true]);
                $this->addField('mandatory_email', ['type' => 'string', 'required' => true]);
                $this->addField('optional_phone', ['type' => 'string']);
            }
        };

        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('mandatory_test');

        // Verify NOT NULL constraints
        self::assertTrue($table->getColumn('required_name')->getNotnull());
        self::assertTrue($table->getColumn('mandatory_email')->getNotnull());
        self::assertFalse($table->getColumn('optional_phone')->getNotnull());
    }

    public function testHasOneRelationship(): void
    {
        // Test hasOne relationships - the ATK4 way
        $postModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('content', ['type' => 'text']);

                // hasOne relationship to user - creates user_id integer field
                $this->hasOne('user_id', [
                    'model' => [UserForRelationTest::class],
                ]);

                // hasOne with custom field name
                $this->hasOne('author_id', [
                    'model' => [UserForRelationTest::class],
                ]);
            }
        };

        $provider = new Atk4SchemaProvider([$postModel]);
        $schema = $provider->createSchema();

        $postTable = $schema->getTable('post');

        // Verify hasOne creates integer fields for foreign keys
        self::assertTrue($postTable->hasColumn('user_id'));
        self::assertTrue($postTable->hasColumn('author_id'));

        // Verify they are integer type (for foreign keys)
        $userIdType = $postTable->getColumn('user_id')->getType()->getName();
        self::assertContains($userIdType, ['integer', 'bigint']);

        $authorIdType = $postTable->getColumn('author_id')->getType()->getName();
        self::assertContains($authorIdType, ['integer', 'bigint']);

        // Verify they are unsigned (foreign keys should be unsigned)
        self::assertTrue($postTable->getColumn('user_id')->getUnsigned());
        self::assertTrue($postTable->getColumn('author_id')->getUnsigned());
    }

    public function testHasManyDoesNotCreateFields(): void
    {
        // hasMany is a REVERSE relationship - it doesn't create fields on this model
        // The foreign key is on the OTHER side
        $userModel = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');

                // hasMany doesn't create any fields in the user table
                // The post table has the user_id field
                $this->hasMany('Posts', [
                    'model' => [PostForRelationTest::class],
                    'ourField' => 'id',
                    'theirField' => 'user_id',
                ]);
            }
        };

        $provider = new Atk4SchemaProvider([$userModel]);
        $schema = $provider->createSchema();

        $userTable = $schema->getTable('user');

        // Verify hasMany does NOT create a 'Posts' or 'posts_id' field
        self::assertTrue($userTable->hasColumn('id'));
        self::assertTrue($userTable->hasColumn('name'));
        self::assertFalse($userTable->hasColumn('Posts'));
        self::assertFalse($userTable->hasColumn('posts'));
        self::assertFalse($userTable->hasColumn('posts_id'));

        // Only id and name fields should exist
        self::assertCount(2, $userTable->getColumns());
    }

    public function testNoForeignKeyConstraintsCreated(): void
    {
        // ATK4 creates integer fields for relationships but does NOT create
        // database-level FOREIGN KEY constraints
        $postModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->hasOne('user_id', [
                    'model' => [UserForRelationTest::class],
                ]);
            }
        };

        // Create the table
        (new Migrator($postModel))->create();

        // Check that no foreign key constraints exist
        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        $table = $schemaManager->introspectTable('post');

        // SQLite supports foreign keys, so we can check
        $foreignKeys = $table->getForeignKeys();

        // ATK4 doesn't create foreign key constraints by default
        self::assertEmpty($foreignKeys, 'ATK4 should not create database foreign key constraints');
    }

    public function testCustomPrimaryKey(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'custom_pk';
            public $idField = 'custom_id'; // Changed from $id_field (3.x) to $idField (6.x)

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
            }
        };

        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('custom_pk');

        // Verify custom primary key
        self::assertTrue($table->hasColumn('custom_id'), 'Should have custom_id column');
        self::assertTrue($table->hasPrimaryKey(), 'Table should have a primary key');

        $pk = $table->getPrimaryKey();
        $pkColumns = $pk->getColumns();

        self::assertCount(1, $pkColumns, 'Should have exactly one primary key column');
        // Strip identifier quotes (backticks, double quotes) from column name
        $actualPk = trim($pkColumns[0], '`"\' ');
        self::assertSame('custom_id', $actualPk, 'Primary key should be custom_id');
    }

    public function testMultipleModelsInOneSchema(): void
    {
        $userModel = new class($this->persistence) extends Model {
            public $table = 'users';

            protected function init(): void
            {
                parent::init();
                $this->addField('username');
                $this->addField('email');
            }
        };

        $postModel = new class($this->persistence) extends Model {
            public $table = 'posts';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('content', ['type' => 'text']);
            }
        };

        $commentModel = new class($this->persistence) extends Model {
            public $table = 'comments';

            protected function init(): void
            {
                parent::init();
                $this->addField('comment', ['type' => 'text']);
                $this->addField('created_at', ['type' => 'datetime']);
            }
        };

        $provider = new Atk4SchemaProvider([$userModel, $postModel, $commentModel]);
        $schema = $provider->createSchema();

        // Verify all tables exist
        self::assertTrue($schema->hasTable('users'));
        self::assertTrue($schema->hasTable('posts'));
        self::assertTrue($schema->hasTable('comments'));

        // Verify fields in each table
        self::assertTrue($schema->getTable('users')->hasColumn('username'));
        self::assertTrue($schema->getTable('posts')->hasColumn('content'));
        self::assertTrue($schema->getTable('comments')->hasColumn('created_at'));
    }

    public function testNeverPersistField(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'never_persist_test';

            protected function init(): void
            {
                parent::init();
                $this->addField('persisted_field');
                $this->addField('calculated_field', ['neverPersist' => true]);
            }
        };

        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('never_persist_test');

        // Verify persisted field exists
        self::assertTrue($table->hasColumn('persisted_field'));

        // Verify never_persist field does NOT exist
        self::assertFalse($table->hasColumn('calculated_field'));
    }

    public function testMigrationDetectsFieldTypeChange(): void
    {
        // Create initial table with string field
        $initialModel = new class($this->persistence) extends Model {
            public $table = 'type_change';

            protected function init(): void
            {
                parent::init();
                $this->addField('value', ['type' => 'string']);
            }
        };

        (new Migrator($initialModel))->create();

        // Verify initial type is string in actual database
        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        $columns = $schemaManager->listTableColumns('type_change');
        $columnMap = [];
        foreach ($columns as $col) {
            $columnMap[$col->getName()] = $col;
        }

        $initialType = $columnMap['value']->getType()->getName();
        self::assertSame('string', $initialType, 'Initial column type should be string');

        // Change field type to integer
        $updatedModel = new class($this->persistence) extends Model {
            public $table = 'type_change';

            protected function init(): void
            {
                parent::init();
                $this->addField('value', ['type' => 'integer']);
            }
        };

        // Generate diff
        $provider = new Atk4SchemaProvider([$updatedModel]);
        $targetSchema = $provider->createSchema();

        $currentSchema = $schemaManager->createSchema();

        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($currentSchema, $targetSchema);

        $platform = $this->persistence->getConnection()->getDatabasePlatform();
        $migrationSqls = $schemaDiff->toSql($platform);

        // Verify migration detects type change (SQLite recreates table)
        self::assertNotEmpty($migrationSqls);

        // Execute migration
        $conn = $this->persistence->getConnection();
        foreach ($migrationSqls as $sql) {
            $conn->executeStatement($conn->expr($sql));
        }

        // Verify type changed to integer in actual database
        $columnsAfter = $schemaManager->listTableColumns('type_change');
        $columnMapAfter = [];
        foreach ($columnsAfter as $col) {
            $columnMapAfter[$col->getName()] = $col;
        }

        $finalType = $columnMapAfter['value']->getType()->getName();
        self::assertContains($finalType, ['integer', 'bigint'], 'Final column type should be integer or bigint');
    }

    public function testMigrationWithMixedRequiredAndOptionalFields(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'mixed_nullable';

            protected function init(): void
            {
                parent::init();
                $this->addField('required_string', ['type' => 'string', 'required' => true]);
                $this->addField('optional_string', ['type' => 'string']);
                $this->addField('required_int', ['type' => 'integer', 'required' => true]);
                $this->addField('optional_int', ['type' => 'integer']);
            }
        };

        // Create table
        (new Migrator($model))->create();

        // Verify table created successfully
        $schemaManager = $this->persistence->getConnection()->createSchemaManager();
        self::assertTrue($schemaManager->tablesExist(['mixed_nullable']));

        // Check column constraints
        $columns = $schemaManager->listTableColumns('mixed_nullable');
        $columnMap = [];
        foreach ($columns as $col) {
            $columnMap[$col->getName()] = $col;
        }

        self::assertTrue($columnMap['required_string']->getNotnull());
        self::assertFalse($columnMap['optional_string']->getNotnull());
        self::assertTrue($columnMap['required_int']->getNotnull());
        self::assertFalse($columnMap['optional_int']->getNotnull());
    }
}

/**
 * Helper model for testing hasOne relationships.
 */
class UserForRelationTest extends Model
{
    public $table = 'user';

    protected function init(): void
    {
        parent::init();
        $this->addField('name');
        $this->addField('email');
    }
}

/**
 * Helper model for testing hasMany relationships.
 */
class PostForRelationTest extends Model
{
    public $table = 'post';

    protected function init(): void
    {
        parent::init();
        $this->addField('title');
        $this->addField('user_id', ['type' => 'integer']);
    }
}
