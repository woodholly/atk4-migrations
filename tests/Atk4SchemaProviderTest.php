<?php

declare(strict_types=1);

namespace Atk4\Migrations\Tests;

use Atk4\Data\Model;
use Atk4\Data\Persistence;
use Atk4\Migrations\Atk4SchemaProvider;
use PHPUnit\Framework\TestCase;

class Atk4SchemaProviderTest extends TestCase
{
    private Persistence\Sql $persistence;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary SQLite database file
        $this->dbFile = sys_get_temp_dir() . '/atk4-migrations-test-' . uniqid() . '.db';
        $this->persistence = new Persistence\Sql('sqlite:' . $this->dbFile);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up database file
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testCreateSchemaFromSingleModel(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name', ['type' => 'string']);
                $this->addField('email', ['type' => 'string']);
                $this->addField('age', ['type' => 'integer']);
            }
        };

        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();

        // Verify schema has the table
        self::assertTrue($schema->hasTable('user'));

        $table = $schema->getTable('user');

        // Verify columns exist
        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->hasColumn('name'));
        self::assertTrue($table->hasColumn('email'));
        self::assertTrue($table->hasColumn('age'));

        // Verify primary key exists
        self::assertTrue($table->hasPrimaryKey());
    }

    public function testCreateSchemaFromMultipleModels(): void
    {
        $userModel = new class($this->persistence) extends Model {
            public $table = 'user';

            protected function init(): void
            {
                parent::init();
                $this->addField('name');
            }
        };

        $postModel = new class($this->persistence) extends Model {
            public $table = 'post';

            protected function init(): void
            {
                parent::init();
                $this->addField('title');
                $this->addField('content', ['type' => 'text']);
            }
        };

        $provider = new Atk4SchemaProvider([$userModel, $postModel]);
        $schema = $provider->createSchema();

        // Verify both tables exist
        self::assertTrue($schema->hasTable('user'));
        self::assertTrue($schema->hasTable('post'));

        $userTable = $schema->getTable('user');
        $postTable = $schema->getTable('post');

        self::assertTrue($userTable->hasColumn('name'));
        self::assertTrue($postTable->hasColumn('title'));
        self::assertTrue($postTable->hasColumn('content'));
    }

    public function testSchemaIncludesColumnTypes(): void
    {
        $model = new class($this->persistence) extends Model {
            public $table = 'test';

            protected function init(): void
            {
                parent::init();
                $this->addField('name', ['type' => 'string']);
                $this->addField('age', ['type' => 'integer']);
                $this->addField('is_active', ['type' => 'boolean']);
                $this->addField('bio', ['type' => 'text']);
            }
        };

        $provider = new Atk4SchemaProvider([$model]);
        $schema = $provider->createSchema();
        $table = $schema->getTable('test');

        // Verify column types
        self::assertSame('string', $table->getColumn('name')->getType()->getName());
        self::assertContains($table->getColumn('age')->getType()->getName(), ['integer', 'bigint']);
        self::assertSame('boolean', $table->getColumn('is_active')->getType()->getName());
        self::assertSame('text', $table->getColumn('bio')->getType()->getName());
    }
}
