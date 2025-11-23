<?php

declare(strict_types=1);

/**
 * ATK4 Migrations Configuration.
 *
 * Copy this file to your project root as 'migrations.php' and customize.
 */

use App\Model\Comment;
use App\Model\Post;
use App\Model\User;
use Atk4\Data\Persistence;

return [
    // Database connection - return Persistence instance
    'persistence' => static function () {
        return new Persistence\Sql('mysql://user:password@localhost/dbname');
    },

    // List of Model classes to track
    'models' => [
        User::class,
        Post::class,
        Comment::class,
    ],

    // Doctrine Migrations settings
    'table_storage' => [
        'table_name' => 'doctrine_migration_versions',
        'version_column_name' => 'version',
        'version_column_length' => 191,
        'executed_at_column_name' => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],

    'migrations_paths' => [
        'Database\Migrations' => 'migrations',
    ],

    'all_or_nothing' => true,
    'transactional' => true,
    'check_database_platform' => false,
    'organize_migrations' => 'none', // 'none', 'year', 'year_and_month'
];
