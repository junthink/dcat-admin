<?php

use Dcat\Admin\AdminServiceProvider;
use Dcat\Admin\Http\Controllers\AuthController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;

require_once __DIR__.'/../../vendor/autoload.php';

$runtime = __DIR__.'/../.browser-runtime';
$app = Application::configure(basePath: $runtime)
    ->withProviders([AdminServiceProvider::class])
    ->withMiddleware()
    ->withExceptions()
    ->withRouting(web: __DIR__.'/routes.php')
    ->create();
$app->useLangPath(realpath(__DIR__.'/../../resources/lang'));
$app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($runtime) {
    $admin = require __DIR__.'/../../config/admin.php';
    $admin['auth']['controller'] = AuthController::class;
    $admin['database']['connection'] = 'sqlite';
    $admin['directory'] = $runtime.'/app/Admin';
    $admin['helpers']['enable'] = false;
    if (in_array($_GET['theme'] ?? '', ['blue', 'blue-light', 'green'], true)) {
        $admin['layout']['color'] = $_GET['theme'];
    }
    $app['config']->set('admin', $admin);
    $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
    $app['config']->set('app.env', 'local');
    $app['config']->set('app.debug', true);
    $app['config']->set('app.url', getenv('DCAT_BROWSER_URL') ?: 'http://127.0.0.1:8317');
    $app['config']->set('app.locale', 'en');
    $app['config']->set('auth.guards.admin', $admin['auth']['guards']['admin']);
    $app['config']->set('auth.providers.admin', $admin['auth']['providers']['admin']);
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => $runtime.'/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]);
    $app['config']->set('cache.default', 'file');
    $app['config']->set('cache.stores.file', ['driver' => 'file', 'path' => $runtime.'/storage/framework/cache']);
    $app['config']->set('session.driver', 'file');
    $app['config']->set('session.serialization', 'json');
    $app['config']->set('session.files', $runtime.'/storage/framework/sessions');
    $app['config']->set('session.cookie', 'dcat_browser_regression');
    $app['config']->set('session.secure', false);
    $app['config']->set('view.paths', [__DIR__.'/../../resources/views']);
    $app['config']->set('view.compiled', $runtime.'/storage/framework/views');
    $app['config']->set('filesystems.disks.public', ['driver' => 'local', 'root' => $runtime.'/public/storage', 'url' => '/storage', 'visibility' => 'public', 'throw' => true]);
    $app['config']->set('hashing.bcrypt.rounds', 4);
});

return $app;
