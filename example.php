<?php

declare(strict_types=1);

/**
 * Example: Using ATK4 Migrations.
 *
 * This demonstrates how to use woodholly/atk4-migrations in your project.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Atk4\Data\Model;
use Atk4\Data\Persistence;

// ============================================================================
// 1. Define your Models
// ============================================================================

class User extends Model
{
    public $table = 'user';

    protected function init(): void
    {
        parent::init();

        $this->addField('name', ['type' => 'string']);
        $this->addField('email', ['type' => 'string']);
        $this->addField('is_active', ['type' => 'boolean']);
        $this->addField('created_at', ['type' => 'datetime']);
    }
}

class Post extends Model
{
    public $table = 'post';

    protected function init(): void
    {
        parent::init();

        $this->addField('title', ['type' => 'string']);
        $this->addField('content', ['type' => 'text']);
        $this->addField('published_at', ['type' => 'datetime']);

        $this->hasOne('user_id', ['model' => User::class]);
    }
}

// ============================================================================
// 2. Create migrations.php configuration file
// ============================================================================

/*
Create migrations.php in your project root:

<?php

use Atk4\Data\Persistence;

return [
    'persistence' => function () {
        return new Persistence\Sql('mysql://user:password@localhost/myapp');
    },

    'models' => [
        User::class,
        Post::class,
    ],

    'migrations_paths' => [
        'Database\\Migrations' => 'migrations',
    ],
];

*/

// ============================================================================
// Typical Workflow:
// ============================================================================

/*

1. Make changes to your Models (add/remove fields, etc.)

2. Generate migration:
   $ php migrations-cli.php diff

3. Review the generated migration file in migrations/

4. Apply migration:
   $ php migrations-cli.php migrate

5. Rollback if needed:
   $ php migrations-cli.php migrate prev

*/
