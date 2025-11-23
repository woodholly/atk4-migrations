<?php

declare(strict_types=1);

namespace Atk4\Migrations\Tests;

use Atk4\Data\Model;
use Atk4\Data\Persistence;
use Atk4\Migrations\ConfigLoader;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    private string $configFile;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbFile = sys_get_temp_dir() . '/test-' . uniqid() . '.db';
        $this->configFile = sys_get_temp_dir() . '/config-' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testLoadValidConfig(): void
    {
        $configContent = <<<PHP
            <?php
            use Atk4\\Data\\Persistence;

            return [
                'persistence' => function () {
                    return new Persistence\\Sql('sqlite:{$this->dbFile}');
                },
                'models' => [
                    \\Atk4\\Migrations\\Tests\\TestUserModel::class,
                ],
                'migrations_paths' => [
                    'Tests\\\\Migrations' => 'tests/migrations',
                ],
            ];
            PHP;
        file_put_contents($this->configFile, $configContent);

        $loader = new ConfigLoader($this->configFile);

        self::assertInstanceOf(Persistence\Sql::class, $loader->getPersistence());
        self::assertCount(1, $loader->getModels());
        self::assertInstanceOf(Model::class, $loader->getModels()[0]);
    }

    public function testMissingConfigFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        new ConfigLoader('/nonexistent/config.php');
    }

    public function testMissingPersistenceKey(): void
    {
        file_put_contents($this->configFile, '<?php return ["models" => []];');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Configuration must define 'persistence'");

        new ConfigLoader($this->configFile);
    }

    public function testMissingModelsKey(): void
    {
        $configContent = <<<PHP
            <?php
            use Atk4\\Data\\Persistence;

            return [
                'persistence' => function () {
                    return new Persistence\\Sql('sqlite:{$this->dbFile}');
                },
            ];
            PHP;
        file_put_contents($this->configFile, $configContent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Configuration must define 'models' array");

        new ConfigLoader($this->configFile);
    }

    public function testEmptyModelsArray(): void
    {
        $configContent = <<<PHP
            <?php
            use Atk4\\Data\\Persistence;

            return [
                'persistence' => function () {
                    return new Persistence\\Sql('sqlite:{$this->dbFile}');
                },
                'models' => [],
            ];
            PHP;
        file_put_contents($this->configFile, $configContent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Configuration 'models' array cannot be empty");

        new ConfigLoader($this->configFile);
    }

    public function testModelsAsClassNames(): void
    {
        $configContent = <<<PHP
            <?php
            use Atk4\\Data\\Persistence;

            return [
                'persistence' => function () {
                    return new Persistence\\Sql('sqlite:{$this->dbFile}');
                },
                'models' => [
                    \\Atk4\\Migrations\\Tests\\TestUserModel::class,
                ],
                'migrations_paths' => [
                    'Tests\\\\Migrations' => 'tests/migrations',
                ],
            ];
            PHP;
        file_put_contents($this->configFile, $configContent);

        $loader = new ConfigLoader($this->configFile);
        $models = $loader->getModels();

        self::assertCount(1, $models);
        self::assertSame('test_user', $models[0]->table);
    }
}

class TestUserModel extends Model
{
    public $table = 'test_user';

    protected function init(): void
    {
        parent::init();
        $this->addField('name');
        $this->addField('email');
    }
}
