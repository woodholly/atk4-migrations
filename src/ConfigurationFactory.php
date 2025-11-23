<?php

declare(strict_types=1);

namespace Atk4\Migrations;

use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Provider\SchemaProvider;

/**
 * Factory to create Doctrine Migrations DependencyFactory with ATK4 integration.
 */
class ConfigurationFactory
{
    /**
     * Create DependencyFactory from configuration file.
     *
     * @param string|null $configFile Path to migrations.php (defaults to ./migrations.php)
     */
    public static function fromConfigFile(?string $configFile = null): DependencyFactory
    {
        if ($configFile === null) {
            $configFile = getcwd() . '/migrations.php';
        }

        // Load and validate configuration
        $configLoader = new ConfigLoader($configFile);

        // Get Doctrine DBAL connection from ATK4 persistence
        $connection = $configLoader->getPersistence()->getConnection()->getConnection();

        // Disable nested transactions to avoid "SAVEPOINT does not exist" errors
        // This prevents Doctrine from using savepoints for transaction nesting,
        // which can cause issues with MySQL/MariaDB when transaction state changes
        $connection->setNestTransactionsWithSavepoints(false);

        // Create temporary config file for Doctrine Migrations
        $migrationConfig = $configLoader->getMigrationConfig();
        $tempConfigFile = self::createTempConfigFile($migrationConfig);

        $config = new PhpFile($tempConfigFile);

        // Create DependencyFactory with existing connection
        $dependencyFactory = DependencyFactory::fromConnection(
            $config,
            new ExistingConnection($connection)
        );

        // Register our custom SchemaProvider
        $schemaProvider = new Atk4SchemaProvider($configLoader->getModels());
        $dependencyFactory->setService(SchemaProvider::class, $schemaProvider);

        return $dependencyFactory;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createTempConfigFile(array $config): string
    {
        $tempFile = sys_get_temp_dir() . '/atk4-migrations-' . uniqid() . '.php';
        $content = '<?php return ' . var_export($config, true) . ';';
        file_put_contents($tempFile, $content);
        register_shutdown_function(static function () use ($tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        });

        return $tempFile;
    }
}
