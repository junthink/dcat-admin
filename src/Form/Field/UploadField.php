<?php

namespace Dcat\Admin\Form\Field;

use Dcat\Admin\Exception\UploadException;
use Dcat\Admin\Support\UploadOwnership;
use Dcat\Admin\Traits\HasUploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

trait UploadField
{
    use HasUploadedFile {
        disk as _disk;
    }

    /**
     * Upload directory.
     *
     * @var string
     */
    protected $directory = '';

    protected $storageDisk;

    /**
     * File name.
     *
     * @var null
     */
    protected $name = null;

    /**
     * Storage instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $storage;

    /**
     * If use unique name to store upload file.
     *
     * @var bool
     */
    protected $useUniqueName = false;

    /**
     * If use sequence name to store upload file.
     *
     * @var bool
     */
    protected $useSequenceName = false;

    /**
     * Controls the storage permission. Could be 'private' or 'public'.
     *
     * @var string
     */
    protected $storagePermission;

    /**
     * @var string
     */
    protected $tempFilePath;

    /**
     * Retain file when delete record from DB.
     *
     * @var bool
     */
    protected $retainable = false;

    /**
     * @var bool
     */
    protected $saveFullUrl = false;

    /**
     * Initialize the storage instance.
     *
     * @return void.
     */
    protected function initStorage()
    {
        $this->disk(config('admin.upload.disk'));

        if (! $this->storage) {
            $this->storage = false;
        }
    }

    /**
     * If name already exists, rename it.
     *
     * @param $file
     * @return void
     */
    public function renameIfExists(UploadedFile $file)
    {
        if ($this->getStorage()->exists("{$this->getDirectory()}/$this->name")) {
            $this->name = $this->generateUniqueName($file);
        }
    }

    /**
     * @return string
     */
    protected function getUploadPath()
    {
        return "{$this->getDirectory()}/$this->name";
    }

    /**
     * Get store name of upload file.
     *
     * @param  UploadedFile  $file
     * @return string
     */
    protected function getStoreName(UploadedFile $file)
    {
        if ($this->useUniqueName) {
            return $this->generateUniqueName($file);
        }

        if ($this->useSequenceName) {
            return $this->generateSequenceName($file);
        }

        if ($this->name instanceof \Closure) {
            $this->name = $this->name->call($this->values(), $file);
        }

        if ($this->name !== '' && is_string($this->name)) {
            return $this->name;
        }

        return $file->getClientOriginalName();
    }

    /**
     * Get directory for store file.
     *
     * @return mixed|string
     */
    public function getDirectory()
    {
        if ($this->directory instanceof \Closure) {
            $this->directory = $this->directory->call($this->values(), $this->form);
        }

        return $this->directory ?: $this->defaultDirectory();
    }

    /**
     * Indicates if the underlying field is retainable.
     *
     * @param  bool  $retainable
     * @return $this
     */
    public function retainable(bool $retainable = true)
    {
        $this->retainable = $retainable;

        return $this;
    }

    public function saveFullUrl(bool $value = true)
    {
        $this->saveFullUrl = $value;

        return $this;
    }

    /**
     * Upload File.
     *
     * @param  UploadedFile  $file
     * @return Response
     */
    public function upload(UploadedFile $file)
    {
        $request = request();

        $id = $request->get('_id');

        if (! $id) {
            return $this->responseErrorMessage('Missing id');
        }

        if ($errors = $this->getValidationErrors($file)) {
            return $this->responseValidationMessage($errors);
        }

        $this->name = $this->getStoreName($file);

        if ($this->options['override']) {
            $this->remove();
        }

        $this->renameIfExists($file);

        $this->prepareFile($file);

        if (! is_null($this->storagePermission)) {
            $result = $this->getStorage()->putFileAs($this->getDirectory(), $file, $this->name, $this->storagePermission);
        } else {
            $result = $this->getStorage()->putFileAs($this->getDirectory(), $file, $this->name);
        }

        if ($result) {
            $path = $this->getUploadPath();
            UploadOwnership::record($this->storageDisk ?: config('admin.upload.disk'), $path);
            $url = $this->objectUrl($path);

            // 上传成功
            return $this->responseUploaded($this->saveFullUrl ? $url : $path, $url);
        }

        // 上传失败
        throw new UploadException(trans('admin.uploader.upload_failed'));
    }

    public function remove()
    {
        $path = "{$this->getDirectory()}/$this->name";
        if ($this->getStorage()->exists($path)) {
            $this->assertCanDeleteFiles($path);
            $this->getStorage()->delete($path);
            UploadOwnership::forget($this->storageDisk, $path);
        }
    }

    /**
     * @param  UploadedFile  $file
     */
    protected function prepareFile(UploadedFile $file)
    {
    }

    /**
     * Specify the directory and name for upload file.
     *
     * @param  string|\Closure  $directory
     * @param  null|string  $name
     * @return $this
     */
    public function move($directory, $name = null)
    {
        $this->dir($directory);

        $this->name($name);

        return $this;
    }

    /**
     * Specify the directory upload file.
     *
     * @param  string|\Closure  $dir
     * @return $this
     */
    public function dir($dir)
    {
        if ($dir) {
            $this->directory = $dir;
        }

        return $this;
    }

    /**
     * Set name of store name.
     *
     * @param  string|callable  $name
     * @return $this
     */
    public function name($name)
    {
        if ($name) {
            $this->name = $name;
        }

        return $this;
    }

    /**
     * Use unique name for store upload file.
     *
     * @return $this
     */
    public function uniqueName()
    {
        $this->useUniqueName = true;

        return $this;
    }

    /**
     * Use sequence name for store upload file.
     *
     * @return $this
     */
    public function sequenceName()
    {
        $this->useSequenceName = true;

        return $this;
    }

    /**
     * Generate a unique name for uploaded file.
     *
     * @param  UploadedFile  $file
     * @return string
     */
    protected function generateUniqueName(UploadedFile $file)
    {
        return md5(uniqid()).'.'.$file->getClientOriginalExtension();
    }

    /**
     * Generate a sequence name for uploaded file.
     *
     * @param  UploadedFile  $file
     * @return string
     */
    protected function generateSequenceName(UploadedFile $file)
    {
        $index = 1;
        $extension = $file->getClientOriginalExtension();
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $newName = $originalName.'_'.$index.'.'.$extension;

        while ($this->getStorage()->exists("{$this->getDirectory()}/$newName")) {
            $index++;
            $newName = $originalName.'_'.$index.'.'.$extension;
        }

        return $newName;
    }

    /**
     * @param  UploadedFile  $file
     * @return string|false|null
     */
    protected function getValidationErrors(UploadedFile $file)
    {
        $data = $rules = $attributes = [];

        // 如果文件上传有错误，则直接返回错误信息
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $file->getErrorMessage();
        }

        // Browser accept filters do not validate uploads on the server.
        $name = rtrim(strtolower($file->getClientOriginalName()), '. ');
        if (preg_match('/[\x00-\x1f\x7f]/', $name) || in_array($name, ['.htaccess', '.user.ini'], true)
            || preg_match('/(?:^|\.)(?:php[0-9]*|phtml|pht|phar|phps|html?|xhtml|shtml|svgz?|js|mjs|cjs)(?:\.|$)/i', $name)
            || in_array($file->getMimeType(), [
                'text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/x-httpd-php',
                'application/x-php', 'text/x-php', 'application/javascript', 'text/javascript',
            ], true)) {
            return 'Executable and active-content uploads are not allowed.';
        }

        if (! $fieldRules = $this->getRules()) {
            return false;
        }

        Arr::set($rules, $this->column, $fieldRules);
        Arr::set($attributes, $this->column, $this->label);
        Arr::set($data, $this->column, $file);

        /* @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($data, $rules, $this->validationMessages, $attributes);

        if (! $validator->passes()) {
            $errors = $validator->errors()->getMessages()[$this->column];

            return implode('<br> ', $errors);
        }
    }

    /**
     * Destroy original files.
     *
     * @return void.
     */
    public function destroy()
    {
        $this->deleteFile($this->original);
    }

    /**
     * Destroy original files.
     *
     * @param $file
     */
    public function destroyIfChanged($file)
    {
        if (! $file || ! $this->original) {
            return $this->destroy();
        }

        $file = array_filter((array) $file);
        $original = (array) $this->original;

        $this->deleteFile(Arr::except(array_combine($original, $original), $file));
    }

    /**
     * Destroy files.
     *
     * @param  string|array  $path
     */
    public function deleteFile($paths)
    {
        if (! $paths || $this->retainable) {
            return;
        }

        if (request()->has(static::FILE_DELETE_FLAG)) {
            $this->assertCanDeleteFiles($paths);
        }

        $storage = $this->getStorage();

        foreach ((array) $paths as $path) {
            $path = $this->normalizeUploadPath($path);
            if (method_exists($this, 'destroyThumbnail')) {
                $this->destroyThumbnail($path);
            }
            if ($storage->exists($path)) {
                $storage->delete($path);
            }
            UploadOwnership::forget($this->storageDisk, $path);
        }
    }

    /** Only server-loaded record values and this user's pending uploads may be deleted. */
    public function assertCanDeleteFiles($paths): void
    {
        $this->getStorage();
        $original = array_map([$this, 'normalizeUploadPath'], array_filter((array) $this->original));
        foreach ((array) $paths as $path) {
            $path = $this->normalizeUploadPath($path);
            abort_unless(in_array($path, $original, true) || UploadOwnership::owns($this->storageDisk, $path), 403, 'This file does not belong to this form or user.');
        }
    }

    protected function normalizeUploadPath($path): string
    {
        abort_unless(is_string($path) && $path !== '', 422, 'Invalid upload path.');
        $prefix = rtrim($this->getStorage()->url(''), '/').'/';
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }
        abort_if(preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || str_starts_with($path, '/')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || str_contains($path, '://'), 422, 'Invalid upload path.');

        return $path;
    }

    /**
     * Get storage instance.
     *
     * @return \Illuminate\Filesystem\Filesystem|null
     */
    public function getStorage()
    {
        if ($this->storage === null) {
            $this->initStorage();
        }

        return $this->storage;
    }

    /**
     * Set disk for storage.
     *
     * @param  string  $disk  Disks defined in `config/filesystems.php`.
     * @return $this
     *
     * @throws \Exception
     */
    public function disk($disk)
    {
        $this->storageDisk = $disk;
        try {
            $this->storage = Storage::disk($disk);
        } catch (\Exception $exception) {
            if (! array_key_exists($disk, config('filesystems.disks'))) {
                admin_error(
                    'Config error.',
                    "Disk [$disk] not configured, please add a disk config in `config/filesystems.php`."
                );

                return $this;
            }

            throw $exception;
        }

        return $this;
    }

    /**
     * Get file visit url.
     *
     * @param  string  $path
     * @return string
     */
    public function objectUrl($path)
    {
        if (URL::isValidUrl($path)) {
            return $path;
        }

        return $this->getStorage()->url($path);
    }

    /**
     * @param $permission
     * @return $this
     */
    public function storagePermission($permission)
    {
        $this->storagePermission = $permission;

        return $this;
    }
}
