<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Admin;
use Dcat\Admin\AdminServiceProvider;
use Dcat\Admin\Http\Controllers\AuthController;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useLangPath(realpath(__DIR__.'/../../resources/lang'));

        $config = require __DIR__.'/../../config/admin.php';
        $config['auth']['controller'] = AuthController::class;
        $config['database']['connection'] = 'testing';
        $config['helpers']['enable'] = false;
        $app['config']->set('admin', $config);
        $app['config']->set('auth.guards.admin', $config['auth']['guards']['admin']);
        $app['config']->set('auth.providers.admin', $config['auth']['providers']['admin']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        if (env('DCAT_TEST_DB_DRIVER') === 'mysql') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => env('DCAT_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('DCAT_TEST_DB_PORT', 3306),
                'database' => env('DCAT_TEST_DB_DATABASE', 'dcat_test'),
                'username' => env('DCAT_TEST_DB_USERNAME', 'root'),
                'password' => env('DCAT_TEST_DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]);
        }
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('session.serialization', 'json');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router): void
    {
        Admin::routes();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
