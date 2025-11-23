<?php

declare(strict_types=1);

namespace Atk4\Migrations;

use Atk4\Data\Model;
use Atk4\Data\Persistence;

/**
 * Loads and validates configuration for ATK4 Migrations.
 */
class ConfigLoader
{
    /** @var array<string, mixed> */
    private array $config;
    private Persistence\Sql $persistence;
    /** @var array<Model> */
    private array $models;

    public function __construct(string $configFile)
    {
        if (!file_exists($configFile)) {
            throw new \RuntimeException("Configuration file not found: {$configFile}");
        }

        $this->config = require $configFile;
        $this->validateConfig();
        $this->initializePersistence();
        $this->initializeModels();
    }

    private function validateConfig(): void
    {
        if (!isset($this->config['persistence'])) {
            throw new \RuntimeException("Configuration must define 'persistence'");
        }

        if (!isset($this->config['models']) || !is_array($this->config['models'])) {
            throw new \RuntimeException("Configuration must define 'models' array");
        }

        if (empty($this->config['models'])) {
            throw new \RuntimeException("Configuration 'models' array cannot be empty");
        }
    }

    private function initializePersistence(): void
    {
        $persistence = $this->config['persistence'];

        if (is_callable($persistence)) {
            $persistence = $persistence();
        }

        if (!$persistence instanceof Persistence\Sql) {
            throw new \RuntimeException('Persistence must be instance of Atk4\Data\Persistence\Sql');
        }

        $this->persistence = $persistence;
    }

    private function initializeModels(): void
    {
        $this->models = [];

        foreach ($this->config['models'] as $modelClass) {
            if (is_string($modelClass)) {
                if (!class_exists($modelClass)) {
                    throw new \RuntimeException("Model class not found: {$modelClass}");
                }

                $model = new $modelClass($this->persistence);
            } elseif ($modelClass instanceof Model) {
                $model = $modelClass;
            } else {
                throw new \RuntimeException('Invalid model definition. Must be class name or Model instance.');
            }

            if (!$model instanceof Model) {
                throw new \RuntimeException('Model must extend Atk4\Data\Model: ' . get_class($model));
            }

            $this->models[] = $model;
        }
    }

    public function getPersistence(): Persistence\Sql
    {
        return $this->persistence;
    }

    /**
     * @return array<Model>
     */
    public function getModels(): array
    {
        return $this->models;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMigrationConfig(): array
    {
        // Extract only Doctrine Migrations configuration
        return array_filter($this->config, static function ($key) {
            return !in_array($key, ['persistence', 'models'], true);
        }, \ARRAY_FILTER_USE_KEY);
    }
}
