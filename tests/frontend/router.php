<?php

$public = realpath(__DIR__.'/../.browser-runtime/public');
$path = realpath($public.'/'.rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
if ($path && str_starts_with($path, $public.'/') && is_file($path)) {
    return false;
}
// Vendor assets are symlinked to the freshly built distribution.
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_starts_with($uri, '/vendor/dcat-admin/')) {
    $assets = realpath(__DIR__.'/../../resources/dist');
    $file = realpath($assets.'/'.substr($uri, strlen('/vendor/dcat-admin/')));
    if ($file && str_starts_with($file, $assets.'/') && is_file($file)) {
        $types = ['js' => 'application/javascript', 'css' => 'text/css', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
        header('Content-Type: '.($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($file);
        return true;
    }
}
$app = require __DIR__.'/app.php';
$app->handleRequest(Illuminate\Http\Request::capture());
