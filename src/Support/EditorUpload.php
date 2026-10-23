<?php

namespace Dcat\Admin\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EditorUpload
{
    public static function store(Request $request, string $field): string
    {
        $request->validate([
            $field => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp,bmp',
                'max:'.(int) config('admin.upload.editor.max_size', 10240)],
            'disk' => ['sometimes', 'required', 'string'],
            'dir' => ['sometimes', 'required', 'string'],
        ]);

        $defaultDisk = config('admin.upload.disk');
        $disk = $request->input('disk', $defaultDisk);
        abort_unless(in_array($disk, (array) config('admin.upload.editor.disks', [$defaultDisk]), true), 422, 'Invalid upload disk.');

        $defaultDirectory = config('admin.upload.directory.image', 'images');
        $directory = $request->input('dir', $defaultDirectory);
        abort_unless(
            is_string($directory) && $directory !== '' && $directory[0] !== '/'
            && ! preg_match('/[\\\\\x00-\x1f\x7f]/', $directory)
            && ! array_intersect(['.', '..'], explode('/', $directory)),
            422,
            'Invalid upload directory.'
        );
        $directory = rtrim($directory, '/');
        $allowed = false;
        foreach ((array) config('admin.upload.editor.directories', [$defaultDirectory]) as $prefix) {
            $prefix = trim($prefix, '/');
            if ($prefix !== '' && ($directory === $prefix || str_starts_with($directory, $prefix.'/'))) {
                $allowed = true;
                break;
            }
        }
        abort_unless($allowed, 422, 'Invalid upload directory.');

        $file = $request->file($field);
        $storage = Storage::disk($disk);
        $path = $storage->putFileAs($directory, $file, $file->hashName());
        abort_unless($path, 500, 'Unable to store uploaded image.');

        return $storage->url($path);
    }
}
