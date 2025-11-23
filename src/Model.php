<?php

declare(strict_types=1);

namespace Atk4\Migrations;

use Atk4\Data\Exception;
use Atk4\Data\Field;
use Atk4\Data\Reference;

/**
 * Extended Model with declarative index and foreign key support.
 *
 * Usage: Just replace "use Atk4\Data\Model" with "use Atk4\Migrations\Model"
 *
 * Example:
 *
 * use Atk4\Migrations\Model;
 *
 * class Post extends Model {
 *     public $table = 'post';
 *
 *     protected function init(): void {
 *         parent::init();
 *
 *         // Indexes in addField()
 *         $this->addField('title', ['index' => true]);
 *         $this->addField('slug', ['unique' => true]);
 *
 *         // Foreign keys + indexes in hasOne()
 *         $this->hasOne('user_id', [
 *             'model' => [User::class],
 *             'onDelete' => 'CASCADE',
 *             'index' => true,
 *         ]);
 *     }
 * }
 */
class Model extends \Atk4\Data\Model
{
    /**
     * Defined indexes for this model.
     *
     * @var array<string, array{fields: list<string>, unique: bool}>
     */
    protected array $indexes = [];

    /**
     * Defined foreign keys for this model.
     *
     * @var array<string, array{localColumns: list<string>, foreignTable: string, foreignColumns: list<string>, onDelete: string|null, onUpdate: string|null}>
     */
    protected array $foreignKeys = [];

    /**
     * Enhanced addField with declarative index support.
     *
     * @param array<mixed>|object $seed Field configuration with optional index/unique
     */
    public function addField(string $name, $seed = []): Field
    {
        // If seed is an object (Field instance from addExpression/addCalculatedField),
        // pass through to parent without modification
        if (is_object($seed)) {
            return parent::addField($name, $seed);
        }

        // At this point, $seed is not an object (PHPStan knows it's array<mixed>)
        // Check if seed is a class-based seed (e.g., [SqlExpressionField::class, [...]])
        // Class-based seeds have the class name at index 0
        if (isset($seed[0]) && is_string($seed[0]) && class_exists($seed[0])) {
            // For class-based seeds, pass through to parent without modification
            // (Expression/calculated fields don't support indexes)
            return parent::addField($name, $seed);
        }

        // Extract index options from seed array
        $index = $seed['index'] ?? null;
        $unique = $seed['unique'] ?? null;
        unset($seed['index'], $seed['unique']);

        // Call parent addField
        $field = parent::addField($name, $seed);

        // Add index if specified
        if ($index !== null || $unique !== null) {
            if ($unique === true) {
                $this->addIndex($name, ['unique' => true]);
            } elseif ($index === true) {
                $this->addIndex($name);
            } elseif ($index === 'unique') {
                $this->addIndex($name, ['unique' => true]);
            } elseif ($index !== false && $index !== null) {
                throw new Exception("Invalid index option: must be true, 'unique', or false");
            }
        }

        return $field;
    }

    /**
     * Enhanced hasOne with declarative foreign key and index support.
     *
     * @param string               $link     Field name for the relationship
     * @param array<string, mixed> $defaults Relationship configuration with optional onDelete/onUpdate/index/unique
     *
     * @return Reference Reference object
     */
    public function hasOne(string $link, array $defaults = []): Reference
    {
        // Extract FK options (flat syntax - ODOO-like)
        $onDelete = $defaults['onDelete'] ?? null;
        $onUpdate = $defaults['onUpdate'] ?? null;

        // Extract index options
        $index = $defaults['index'] ?? null;
        $unique = $defaults['unique'] ?? null;

        unset($defaults['onDelete'], $defaults['onUpdate'], $defaults['index'], $defaults['unique']);

        // Try to extract table name from model before calling parent
        $foreignTable = null;
        if (isset($defaults['model'])) {
            $foreignTable = $this->extractTableFromModelDefinition($defaults['model']);
        }

        // Call parent hasOne
        $ref = parent::hasOne($link, $defaults);

        // Add index if specified
        if ($index !== null || $unique !== null) {
            if ($unique === true) {
                $this->addIndex($link, ['unique' => true]);
            } elseif ($index === true) {
                $this->addIndex($link);
            } elseif ($index === 'unique') {
                $this->addIndex($link, ['unique' => true]);
            }
        }

        // Add foreign key if onDelete or onUpdate specified
        if ($onDelete !== null || $onUpdate !== null) {
            $fkOptions = [];

            // Use extracted table or try auto-detect from reference
            if ($foreignTable !== null) {
                $fkOptions['foreignTable'] = $foreignTable;
            } else {
                // Fallback: try to get table from created reference
                try {
                    $theirModel = $ref->createTheirModel();
                    if (is_string($theirModel->table)) {
                        $fkOptions['foreignTable'] = $theirModel->table;
                    }
                } catch (\Throwable $e) {
                    // Can't auto-detect, will throw error in addForeignKey
                }
            }

            if ($onDelete !== null) {
                $fkOptions['onDelete'] = $onDelete;
            }
            if ($onUpdate !== null) {
                $fkOptions['onUpdate'] = $onUpdate;
            }

            $this->addForeignKey($link, $fkOptions);
        }

        return $ref;
    }

    /**
     * Extract table name from model definition.
     *
     * @param mixed $modelDefinition Model class, array, or closure
     *
     * @return string|null Table name or null if not extractable
     */
    protected function extractTableFromModelDefinition($modelDefinition): ?string
    {
        try {
            // Handle array format: ['model' => ClassName::class] or [ClassName::class]
            if (is_array($modelDefinition)) {
                $modelClass = $modelDefinition[0] ?? $modelDefinition['model'] ?? null;
                if (is_string($modelClass) && class_exists($modelClass)) {
                    $tempModel = new $modelClass($this->getPersistence());

                    return is_string($tempModel->table) ? $tempModel->table : null;
                }
                // If it's a closure in array, extract it
                if (is_callable($modelClass)) {
                    $tempModel = $modelClass();

                    return is_string($tempModel->table) ? $tempModel->table : null;
                }
            }

            // Handle closure format
            if (is_callable($modelDefinition)) {
                $tempModel = $modelDefinition();

                return is_string($tempModel->table) ? $tempModel->table : null;
            }

            // Handle direct class name
            if (is_string($modelDefinition) && class_exists($modelDefinition)) {
                $tempModel = new $modelDefinition($this->getPersistence());

                return is_string($tempModel->table) ? $tempModel->table : null;
            }
        } catch (\Throwable $e) {
            // Couldn't extract table, return null
            return null;
        }

        return null;
    }

    /**
     * Add an index to the model.
     *
     * @param string|array<mixed>                 $fields  Single field name or array of field names
     * @param array{unique?: bool, name?: string} $options Index options
     *
     * @return $this
     */
    public function addIndex($fields, array $options = []): self
    {
        // Normalize fields to array
        if (is_string($fields)) {
            $fields = [$fields];
        }

        // Validate fields
        if (empty($fields)) {
            throw new Exception('Index must have at least one field');
        }

        foreach ($fields as $field) {
            if (!is_string($field)) {
                throw new Exception('Field name must be a string');
            }
        }

        // Generate index name if not provided
        $name = $options['name'] ?? $this->generateIndexName($fields, $options['unique'] ?? false);

        // Store index definition
        $this->indexes[$name] = [
            'fields' => $fields,
            'unique' => $options['unique'] ?? false,
        ];

        return $this;
    }

    /**
     * Add a foreign key constraint to the model.
     *
     * @param string|array<mixed>  $localColumns Local column name(s)
     * @param array<string, mixed> $seed         Configuration seed
     *
     * @return $this
     */
    public function addForeignKey($localColumns, array $seed = []): self
    {
        // Normalize localColumns to array
        if (is_string($localColumns)) {
            $localColumns = [$localColumns];
        }

        // Validate local columns
        if (empty($localColumns)) {
            throw new Exception('Foreign key must have at least one local column');
        }

        foreach ($localColumns as $column) {
            if (!is_string($column) || $column === '') {
                throw new Exception('Local column name must be a non-empty string');
            }
        }

        // Extract foreign table
        $foreignTable = $seed['foreignTable'] ?? $seed['table'] ?? null;

        if ($foreignTable === null || $foreignTable === '') {
            throw new Exception('Foreign table must be specified or detectable from hasOne reference');
        }

        // Extract foreign columns
        $isSingleColumn = count($localColumns) === 1;
        if (isset($seed['foreignColumns'])) {
            $foreignColumns = $seed['foreignColumns'];
            if (!is_array($foreignColumns)) {
                $foreignColumns = [$foreignColumns];
            }
        } elseif (isset($seed['foreignColumn'])) {
            $foreignColumns = [$seed['foreignColumn']];
        } elseif ($isSingleColumn) {
            // Default to 'id' for single column FK
            $foreignColumns = ['id'];
        } else {
            // For composite FK, default to same column names
            $foreignColumns = $localColumns;
        }

        // Validate foreign columns count matches
        if (count($localColumns) !== count($foreignColumns)) {
            throw new Exception('Number of local columns must match number of foreign columns');
        }

        // Validate onDelete and onUpdate actions
        $validActions = ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];

        $onDelete = isset($seed['onDelete']) ? strtoupper($seed['onDelete']) : null;
        if ($onDelete !== null && !in_array($onDelete, $validActions, true)) {
            throw new Exception('Invalid onDelete action: ' . $seed['onDelete']);
        }

        $onUpdate = isset($seed['onUpdate']) ? strtoupper($seed['onUpdate']) : null;
        if ($onUpdate !== null && !in_array($onUpdate, $validActions, true)) {
            throw new Exception('Invalid onUpdate action: ' . $seed['onUpdate']);
        }

        // Generate FK name if not provided
        $name = $seed['name'] ?? $this->generateForeignKeyName($localColumns, $foreignTable);

        // Store foreign key definition
        $this->foreignKeys[$name] = [
            'localColumns' => $localColumns,
            'foreignTable' => $foreignTable,
            'foreignColumns' => $foreignColumns,
            'onDelete' => $onDelete,
            'onUpdate' => $onUpdate,
        ];

        return $this;
    }

    /**
     * Get all indexes defined for this model.
     *
     * @return array<string, array{fields: list<string>, unique: bool}>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Check if model has any indexes defined.
     */
    public function hasIndexes(): bool
    {
        return !empty($this->indexes);
    }

    /**
     * Get all foreign keys defined for this model.
     *
     * @return array<string, array{localColumns: list<string>, foreignTable: string, foreignColumns: list<string>, onDelete: string|null, onUpdate: string|null}>
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Check if model has any foreign keys defined.
     */
    public function hasForeignKeys(): bool
    {
        return !empty($this->foreignKeys);
    }

    /**
     * Generate index name from fields.
     *
     * @param list<string> $fields
     */
    protected function generateIndexName(array $fields, bool $unique): string
    {
        $prefix = $unique ? 'uniq' : 'idx';
        $tableName = is_string($this->table) ? $this->table : 'table';

        return $prefix . '_' . $tableName . '_' . implode('_', $fields);
    }

    /**
     * Generate foreign key constraint name.
     *
     * @param list<string> $localColumns
     */
    protected function generateForeignKeyName(array $localColumns, string $foreignTable): string
    {
        $tableName = is_string($this->table) ? $this->table : 'table';

        return 'fk_' . $tableName . '_' . implode('_', $localColumns) . '_' . $foreignTable;
    }
}
