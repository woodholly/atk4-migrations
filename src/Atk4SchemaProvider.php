<?php

declare(strict_types=1);

namespace Atk4\Migrations;

use Atk4\Data\Model;
use Atk4\Data\Schema\Migrator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\Provider\SchemaProvider;

/**
 * Schema Provider that builds Doctrine Schema from ATK4 Models.
 *
 * This class integrates ATK4 Data with Doctrine Migrations by converting
 * ATK4 Model definitions into Doctrine Schema objects.
 */
class Atk4SchemaProvider implements SchemaProvider
{
    /** @var array<Model> */
    private array $models;

    /**
     * @param array<Model> $models ATK4 Models to include in schema
     */
    public function __construct(array $models)
    {
        $this->models = $models;
    }

    /**
     * Create Doctrine Schema from ATK4 Models.
     *
     * This method:
     * 1. Creates a Migration for each Model
     * 2. Extracts the Doctrine Table object from Migration
     * 3. Adds any indexes/FKs if model supports them (Atk4\Migrations\Model)
     * 4. Builds a complete Schema with all tables
     *
     * @return Schema The target database schema
     */
    public function createSchema(): Schema
    {
        $tables = [];

        foreach ($this->models as $model) {
            // Use ATK4's Migrator to build Doctrine Table internally
            $migrator = new Migrator($model);

            // Extract the Doctrine Table object that Migrator built
            // Migrator->table is a public property containing Doctrine\DBAL\Schema\Table
            $table = $migrator->table;

            // Add indexes if model supports them (Atk4\Migrations\Model)
            if (method_exists($model, 'getIndexes')) {
                $this->addIndexesToTable($table, $model, $migrator);
            }

            // Add foreign keys if model supports them (Atk4\Migrations\Model)
            if (method_exists($model, 'getForeignKeys')) {
                $this->addForeignKeysToTable($table, $model, $migrator);
            }

            $tables[] = $table;
        }

        // Create schema with all tables
        return new Schema($tables);
    }

    /**
     * Add indexes from model to Doctrine Table.
     *
     * @param Table $table
     */
    private function addIndexesToTable($table, Model $model, Migrator $migrator): void
    {
        $indexes = $model->getIndexes();

        if (empty($indexes)) {
            return;
        }

        // Get platform from migrator connection
        $platform = $migrator->getConnection()->getDatabasePlatform();

        foreach ($indexes as $name => $indexDef) {
            $fields = $indexDef['fields'];
            $unique = $indexDef['unique'];

            // Quote field names for the platform
            $quotedFields = array_map(
                static fn ($field) => $platform->quoteSingleIdentifier($field),
                $fields
            );

            // Add index to table - use different methods for unique vs regular indexes
            if ($unique) {
                $table->addUniqueIndex($quotedFields, $name);
            } else {
                $table->addIndex($quotedFields, $name);
            }
        }
    }

    /**
     * Add foreign keys from model to Doctrine Table.
     *
     * @param Table $table
     */
    private function addForeignKeysToTable($table, Model $model, Migrator $migrator): void
    {
        $foreignKeys = $model->getForeignKeys();

        if (empty($foreignKeys)) {
            return;
        }

        // Get platform from migrator connection
        $platform = $migrator->getConnection()->getDatabasePlatform();

        foreach ($foreignKeys as $name => $fkDef) {
            $localColumns = $fkDef['localColumns'];
            $foreignTable = $fkDef['foreignTable'];
            $foreignColumns = $fkDef['foreignColumns'];

            // Quote identifiers for the platform
            $quotedLocalColumns = array_map(
                static fn ($column) => $platform->quoteSingleIdentifier($column),
                $localColumns
            );
            $quotedForeignTable = $platform->quoteSingleIdentifier($foreignTable);
            $quotedForeignColumns = array_map(
                static fn ($column) => $platform->quoteSingleIdentifier($column),
                $foreignColumns
            );

            // Build options array
            $options = [];
            if ($fkDef['onDelete'] !== null) {
                $options['onDelete'] = $fkDef['onDelete'];
            }
            if ($fkDef['onUpdate'] !== null) {
                $options['onUpdate'] = $fkDef['onUpdate'];
            }

            // Add foreign key constraint to table
            $table->addForeignKeyConstraint(
                $quotedForeignTable,
                $quotedLocalColumns,
                $quotedForeignColumns,
                $options,
                $name
            );
        }
    }
}
