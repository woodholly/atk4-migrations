# ATK4 Migrations

Database migrations for ATK4 Data using Doctrine Migrations.

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Installation](#installation)
- [Indexes and Foreign Keys](#indexes-and-foreign-keys)
- [Quick Start](#quick-start)
- [Available Commands](#available-commands)
- [Table Renaming](#table-renaming)
- [Fixing Mistakes](#fixing-mistakes)
- [Advanced](#advanced)
- [Configuration](#configuration)
- [Requirements](#requirements)
- [Testing](#testing)

## Overview

Automatic database migrations for ATK4 Data using Doctrine Migrations. Define your schema in ATK4 Models, generate migrations automatically.

**Works with any database state:** Empty, existing, or production databases. The tool compares your models with the current database and generates only the necessary changes (CREATE, ALTER, or nothing if already in sync).

## Features

- **Automatic Schema Detection** - Compares models with database, generates only necessary changes
- **Full Rollback Support** - Reverse any migration
- **Zero Schema Duplication** - Schema defined once in ATK4 Models
- **Production Ready** - Built on Doctrine Migrations
- **Multi-Database** - MySQL, PostgreSQL, SQLite, Oracle, SQL Server
- **Declarative Constraints** - Define indexes and foreign keys in models (extended Model class)

## Installation

```bash
composer require woodholly/atk4-migrations
```

## Indexes and Foreign Keys

ATK4 Data lacks declarative index/FK support. This package provides `Atk4\Migrations\Model` - an extended Model class that adds:

- Field options: `'index' => true`, `'unique' => true` in `addField()`
- Relationship options: `'onDelete'`, `'onUpdate'`, `'index'`, `'unique'` in `hasOne()`
- Methods: `addIndex()`, `addForeignKey()`, `getIndexes()`, `getForeignKeys()`

**Usage:** Replace `use Atk4\Data\Model` → `use Atk4\Migrations\Model`

> **Note:** Standard `Atk4\Data\Model` works with migrations, but requires manual index/FK editing in each migration file. `Atk4\Migrations\Model` defines constraints declaratively. We hope this will be added to ATK4 Data core eventually.

**Example:**

```php
use Atk4\Migrations\Model;

class Post extends Model
{
    public $table = 'post';

    protected function init(): void
    {
        parent::init();

        // Indexes
        $this->addField('title', ['index' => true]);        // Regular index
        $this->addField('slug', ['unique' => true]);        // Unique index
        $this->addIndex(['title', 'slug']);                 // Composite index

        // Foreign keys (CASCADE, RESTRICT, SET NULL, NO ACTION, SET DEFAULT)
        $this->hasOne('user_id', [
            'model' => [User::class],
            'onDelete' => 'CASCADE',
            'index' => true,                                 // FK + index
        ]);

        // Composite foreign key
        $this->addForeignKey(['product_id', 'warehouse_id'], [
            'foreignTable' => 'inventory',
            'foreignColumns' => ['product_id', 'warehouse_id'],
            'onDelete' => 'RESTRICT',
        ]);
    }
}
```

## Quick Start

### 1. Create Configuration

Create `migrations.php` in project root (or `config/migrations.php` for better organization):

```php
<?php

use Atk4\Data\Persistence;

return [
    // Database connection
    'persistence' => function () {
        return new Persistence\Sql('mysql://user:pass@localhost/dbname');
    },

    // List of Model classes to track
    'models' => [
        \App\Model\User::class,
        \App\Model\Post::class,
        \App\Model\Comment::class,
    ],

    // Where to store migration files (namespace => directory)
    'migrations_paths' => [
        'Database\\Migrations' => 'migrations',  // Class namespace => filesystem path
    ],
];
```

### 2. Generate & Run Migrations

```bash
# Generate migration from model changes
vendor/bin/migrations-cli.php diff

# Preview SQL (always check first!)
vendor/bin/migrations-cli.php migrate --dry-run -vv

# Execute migration
vendor/bin/migrations-cli.php migrate
```

Use `--configuration=config/migrations.php` if config not in project root.

## Available Commands

```bash
# Schema & Migration Generation
vendor/bin/migrations-cli.php diff          # Generate migration from schema diff
vendor/bin/migrations-cli.php generate      # Generate blank migration file
vendor/bin/migrations-cli.php dump-schema   # Dump current database schema to SQL file

# Execution
vendor/bin/migrations-cli.php migrate       # Execute pending migrations
vendor/bin/migrations-cli.php migrate prev  # Rollback one migration
vendor/bin/migrations-cli.php migrate --dry-run -vv  # Preview SQL without executing
vendor/bin/migrations-cli.php execute <version> --up    # Execute specific migration
vendor/bin/migrations-cli.php execute <version> --down  # Rollback specific migration

# Status & Information
vendor/bin/migrations-cli.php status        # Show migration status
vendor/bin/migrations-cli.php list          # List available migrations
vendor/bin/migrations-cli.php up-to-date    # Check if schema is up to date
vendor/bin/migrations-cli.php latest        # Show latest version

# Advanced
vendor/bin/migrations-cli.php version <version> --add     # Mark as executed (without running)
vendor/bin/migrations-cli.php version <version> --delete  # Mark as not-executed (without running)
vendor/bin/migrations-cli.php rollup        # Squash all migrations into one
vendor/bin/migrations-cli.php sync-metadata # Sync metadata storage
```

**Note on `dump-schema`:** This command dumps the current database schema (based on your models) to a SQL file. Useful for debugging schema differences or generating a full schema snapshot. The migrations directory should be empty or the schema reflects what would be created.

## Table Renaming

Schema diff tools cannot distinguish table renames from drop+create operations. Changing `public $table = 'old_name'` to `public $table = 'new_name'` generates **DROP + CREATE**, deleting all data.

**Workflow:**

```bash
# 1. Change table name in model
# 2. Generate migration
vendor/bin/migrations-cli.php diff

# 3. Preview - will show DROP+CREATE
vendor/bin/migrations-cli.php migrate --dry-run -vv

# 4. Manually edit migration file to use renameTable()
```

```php
// migrations/Version20250116120000.php
public function up(Schema $schema): void
{
    // $this->addSql('DROP TABLE old_users');
    // $this->addSql('CREATE TABLE users (...)');
    $schema->renameTable('old_users', 'users');
}

public function down(Schema $schema): void
{
    $schema->renameTable('users', 'old_users');
}
```

```bash
# 5. Verify and execute
vendor/bin/migrations-cli.php migrate --dry-run -vv
vendor/bin/migrations-cli.php migrate
```

**Renaming + modifying fields:** Either create two separate migrations (rename first, then modify), or manually edit the migration to rename then ALTER using the new table name.

## Fixing Mistakes

**Migration not executed yet?** Just delete the file:

```bash
rm migrations/Version20250116120000.php
```

**Migration already executed?** Use `version` command to unmark it:

```bash
# Option 1: Mark as not-executed (without running rollback SQL)
vendor/bin/migrations-cli.php version Version20250116120000 --delete
rm migrations/Version20250116120000.php

# Option 2: Actually rollback the database changes
vendor/bin/migrations-cli.php migrate prev  # Rollback SQL is executed
rm migrations/Version20250116120000.php
```

**Note:** Use `--delete` when the migration didn't actually change anything or when you've manually reverted the changes. Use `migrate prev` when you want to actually reverse the database changes.

**Wrong model definition?** Fix the model and generate a new corrective migration:

```bash
# Fix the error in your model
# Then generate a new migration that will fix the database
vendor/bin/migrations-cli.php diff
vendor/bin/migrations-cli.php migrate --dry-run -vv  # Verify it fixes the issue
vendor/bin/migrations-cli.php migrate
```


## Advanced

### Manual Migration Editing

For advanced cases, manually edit generated migration files using Doctrine's Schema API. See [Doctrine Migrations documentation](https://www.doctrine-project.org/projects/doctrine-migrations/en/latest/index.html) for available methods.

### Auto-Discovery

You can automatically discover models from a directory instead of listing them manually:

```php
<?php

declare(strict_types=1);

use Atk4\Data\Persistence;

// Helper function to discover models from src/Model directory
function discoverModels(string $directory, string $namespace): array
{
    $models = [];
    $files = glob($directory . '/*.php');

    foreach ($files as $file) {
        $className = $namespace . '\\' . basename($file, '.php');

        // Try to load the file first
        if (!class_exists($className)) {
            require_once $file;
        }

        if (class_exists($className)) {
            $models[] = $className;
        }
    }

    return $models;
}

// Load autoloader
require_once __DIR__ . '/vendor/autoload.php';

return [
    'persistence' => function () {
        return new Persistence\Sql('mysql://user:pass@localhost/dbname');
    },

    'models' => discoverModels(__DIR__ . '/src/Model', 'App\\Model'),

    'migrations_paths' => [
        'Database\\Migrations' => 'migrations',
    ],
];
```

This auto-discovery approach requires files in `src/Model/*.php` with class names matching filenames.

## Configuration

All available options:

```php
<?php

use Atk4\Data\Persistence;

return [
    // Required: Database connection
    'persistence' => function () {
        return new Persistence\Sql('mysql://user:pass@localhost/dbname');
    },

    // Required: Models to track
    'models' => [
        \App\Model\User::class,
        \App\Model\Post::class,
    ],

    // Optional: Migration history tracking table (defaults shown)
    'table_storage' => [
        'table_name' => 'doctrine_migration_versions',  // Table that tracks executed migrations
        'version_column_name' => 'version',             // Column storing migration version numbers
    ],

    // Optional: Where migrations are stored (namespace => directory path)
    // Key = PHP namespace for migration classes
    // Value = filesystem directory where migration files are created
    'migrations_paths' => [
        'Database\\Migrations' => 'migrations',
    ],

    // Optional: Wrap all migrations in transaction
    'all_or_nothing' => true,

    // Optional: Each migration in its own transaction
    'transactional' => true,
];
```

### Understanding `table_storage`

The `table_storage` option configures where Doctrine Migrations tracks migration execution history:

**What it does:**

- Creates a table in your database (default: `doctrine_migration_versions`)
- Stores a record each time a migration is executed
- Allows the system to know which migrations have already been applied

**Example table contents:**

```
doctrine_migration_versions
+---------------------------+---------------------+
| version                   | executed_at         |
+---------------------------+---------------------+
| Tests\Version20250116001  | 2025-01-16 10:00:00 |
| Tests\Version20250116002  | 2025-01-16 10:05:00 |
+---------------------------+---------------------+
```

**When to customize:**

- If you already have a table named `doctrine_migration_versions` (avoid conflicts)
- If your project has naming conventions for system tables
- If you're integrating with an existing migration system

**Default values are fine for most projects** - you only need to specify `table_storage` if you want to change the defaults.

## How It Works

```text
Your ATK4 Models
       ↓
Atk4SchemaProvider (implements Doctrine SchemaProviderInterface)
       ↓
Doctrine Comparator (compares with current DB)
       ↓
Generated Migration File
       ↓
Doctrine Migrations (runs/tracks/rollbacks)
```

The package extracts Doctrine Table objects from ATK4's Migrator class (which already builds them internally), then uses Doctrine's built-in schema comparison to generate migrations.

## Requirements

- PHP 7.4 or higher
- `atk4/data ^6.0`
- `doctrine/migrations ^3.5`
- `symfony/console ^5.0 || ^6.0 || ^7.0`

## Testing

```bash
vendor/bin/phpunit
```

## License

MIT

## Credits

Built on top of:

- [ATK4 Data](https://github.com/atk4/data)
- [Doctrine Migrations](https://github.com/doctrine/migrations)
