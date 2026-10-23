<?php

$runtime = __DIR__.'/../.browser-runtime';
foreach (['bootstrap/cache', 'app', 'config', 'public/storage', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
    if (! is_dir($runtime.'/'.$path)) {
        mkdir($runtime.'/'.$path, 0700, true);
    }
}
if (! file_exists($runtime.'/public/vendor/dcat-admin')) {
    mkdir($runtime.'/public/vendor', 0700, true);
    symlink(realpath(__DIR__.'/../../resources/dist'), $runtime.'/public/vendor/dcat-admin');
}
file_put_contents($runtime.'/database.sqlite', '');
$app = require __DIR__.'/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true, '--force' => true]);
config(['admin.auth.initial_username' => 'browser-admin', 'admin.auth.initial_password' => 'browser-regression-password-32chars']);
(new Dcat\Admin\Models\AdminTablesSeeder())->run();
echo "Browser fixture initialized.\n";
