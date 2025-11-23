#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ATK4 Migrations CLI.
 *
 * Runs Doctrine Migrations commands with ATK4 Data integration.
 * Configuration is loaded from ./migrations.php
 */

// Security: Prevent web access
if (\PHP_SAPI !== 'cli') {
    http_response_code(403);

    exit('This script can only be run from command line (CLI).');
}

use Atk4\Migrations\ConfigurationFactory;
use Doctrine\Migrations\Tools\Console\Command;
use Symfony\Component\Console\Application;

// Try to find autoloader
$autoloadPaths = [
    __DIR__ . '/../../autoload.php',          // Installed as vendor package
    __DIR__ . '/vendor/autoload.php',         // Running from package root
    getcwd() . '/vendor/autoload.php',        // Running from project root
];

foreach ($autoloadPaths as $file) {
    if (file_exists($file)) {
        require_once $file;

        break;
    }
}

if (!class_exists(Application::class)) {
    fwrite(\STDERR, "Error: Composer autoloader not found. Run 'composer install' first.\n");

    exit(1);
}

// Load configuration and create DependencyFactory
try {
    // Check for --configuration option
    $configFile = null;
    foreach ($argv as $i => $arg) {
        if (str_starts_with($arg, '--configuration=')) {
            $configFile = substr($arg, strlen('--configuration='));
            // Remove this argument so Doctrine Migrations doesn't see it
            unset($argv[$i]);

            break;
        }
    }
    $argv = array_values($argv); // Re-index array

    if ($configFile === null) {
        $configFile = getcwd() . '/migrations.php';
    }

    if (!file_exists($configFile)) {
        fwrite(\STDERR, "Error: Configuration file not found: {$configFile}\n");
        fwrite(\STDERR, "Create migrations.php in your project root. See vendor/woodholly/atk4-migrations/migrations.php for template.\n");

        exit(1);
    }

    $dependencyFactory = ConfigurationFactory::fromConfigFile($configFile);
} catch (Throwable $e) {
    fwrite(\STDERR, "Error loading configuration: {$e->getMessage()}\n");

    exit(1);
}

// Create CLI application
$cli = new Application('ATK4 Migrations');
$cli->setCatchExceptions(true);

// Register all Doctrine Migrations commands
$cli->addCommands([
    new Command\DiffCommand($dependencyFactory),
    new Command\DumpSchemaCommand($dependencyFactory),
    new Command\ExecuteCommand($dependencyFactory),
    new Command\GenerateCommand($dependencyFactory),
    new Command\LatestCommand($dependencyFactory),
    new Command\ListCommand($dependencyFactory),
    new Command\MigrateCommand($dependencyFactory),
    new Command\RollupCommand($dependencyFactory),
    new Command\StatusCommand($dependencyFactory),
    new Command\SyncMetadataCommand($dependencyFactory),
    new Command\VersionCommand($dependencyFactory),
    new Command\UpToDateCommand($dependencyFactory),
]);

$cli->run();
