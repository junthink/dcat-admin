<?php

namespace Dcat\Admin\Support;

use Dcat\Admin\Admin;
use Illuminate\Support\Facades\Cache;

class UploadOwnership
{
    protected static function owner(): string
    {
        $user = Admin::user();
        $session = request()->hasSession() ? request()->session()->getId() : '';
        abort_unless($user || $session !== '', 403, 'Upload ownership requires an authenticated user or session.');

        return hash('sha256', Admin::app()->getName().'|'.($user ? 'user:'.$user->getAuthIdentifier() : 'session:'.$session));
    }

    protected static function key(string $disk, string $path): string
    {
        return 'dcat-upload-owner:'.hash('sha256', $disk.'|'.$path);
    }

    public static function record(string $disk, string $path): void
    {
        Cache::store(config('admin.upload.registry_store'))->put(static::key($disk, $path), static::owner(), (int) config('admin.upload.ownership_ttl', 86400));
    }

    public static function owns(string $disk, string $path): bool
    {
        return Cache::store(config('admin.upload.registry_store'))->get(static::key($disk, $path)) === static::owner();
    }

    public static function forget(string $disk, string $path): void
    {
        Cache::store(config('admin.upload.registry_store'))->forget(static::key($disk, $path));
    }
}
