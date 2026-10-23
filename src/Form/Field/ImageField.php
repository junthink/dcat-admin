<?php

namespace Dcat\Admin\Form\Field;

use Dcat\Admin\Exception\AdminException;
use Illuminate\Support\Str;
use Dcat\Admin\Support\ImageConstraint;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Drivers\Gd\Driver;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait ImageField
{
    /**
     * Intervention calls.
     *
     * @var array
     */
    protected $interventionCalls = [];

    /**
     * Thumbnail settings.
     *
     * @var array
     */
    protected $thumbnails = [];

    protected static $interventionAlias = [
        'filling' => 'fill',
        'greyscale' => 'grayscale',
        'orientate' => 'orient',
        'limitColors' => 'reduceColors',
    ];

    /**
     * Default directory for file to upload.
     *
     * @return mixed
     */
    public function defaultDirectory()
    {
        return config('admin.upload.directory.image');
    }

    /**
     * Execute Intervention calls.
     *
     * @param  string  $target
     * @param  string  $mime
     * @return mixed
     */
    public function callInterventionMethods($target, $mime)
    {
        if (! empty($this->interventionCalls)) {
            $image = $this->readImage($target);
            foreach ($this->interventionCalls as $call) {
                $image = $this->applyImageCall($image, $call['method'], $call['arguments']);
            }
            $image->encodeUsingMediaType($mime ?: mime_content_type($target))->save($target);
        }

        return $target;
    }

    /**
     * Call intervention methods.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return $this
     *
     * @throws \Exception
     */
    public function __call($method, $arguments)
    {
        if (static::hasMacro($method)) {
            return parent::__call($method, $arguments);
        }

        if (! class_exists(ImageManager::class)) {
            throw new AdminException('To use image handling and manipulation, please install [intervention/image:^4.4] and GD or Imagick first.');
        }

        $this->interventionCalls[] = [
            'method'    => static::$interventionAlias[$method] ?? $method,
            'arguments' => $arguments,
        ];

        return $this;
    }

    /**
     * @param  string|array  $name
     * @param  int  $width
     * @param  int  $height
     * @return $this
     */
    public function thumbnail($name, ?int $width = null, ?int $height = null)
    {
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $key => $size) {
                if (count($size) >= 2) {
                    $this->thumbnails[$key] = $size;
                }
            }
        } elseif (func_num_args() == 3) {
            $this->thumbnails[$name] = [$width, $height];
        }

        return $this;
    }

    /**
     * Destroy original thumbnail files.
     *
     * @param  string|array  $file
     * @param  bool  $force
     * @return void.
     */
    public function destroyThumbnail($file = null, bool $force = false)
    {
        if ($this->retainable && ! $force) {
            return;
        }

        $file = $file ?: $this->original;
        if (! $file) {
            return;
        }

        if (is_array($file)) {
            foreach ($file as $f) {
                $this->destroyThumbnail($f, $force);
            }

            return;
        }

        foreach ($this->thumbnails as $name => $_) {
            // We need to get extension type ( .jpeg , .png ...)
            $ext = pathinfo($file, PATHINFO_EXTENSION);

            // We remove extension from file name so we can append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $file);

            // We merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            if ($this->getStorage()->exists($path)) {
                $this->getStorage()->delete($path);
            }
        }
    }

    /**
     * Upload file and delete original thumbnail files.
     *
     * @param  UploadedFile  $file
     * @return $this
     */
    protected function uploadAndDeleteOriginalThumbnail(UploadedFile $file)
    {
        foreach ($this->thumbnails as $name => $size) {
            // We need to get extension type ( .jpeg , .png ...)
            $ext = pathinfo($this->name, PATHINFO_EXTENSION);

            // We remove extension from file name so we can append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $this->name);

            // We merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            $image = $this->readImage($file->getRealPath());
            $action = $size[2] ?? 'resize';
            $image = $this->applyImageCall($image, $action === 'resize' ? 'scale' : $action, [$size[0], $size[1]]);
            $encoded = (string) $image->encodeUsingFileExtension($ext);
            $options = $this->storagePermission === null ? [] : ['visibility' => $this->storagePermission];
            $this->getStorage()->put("{$this->getDirectory()}/{$path}", $encoded, $options);
        }

        if (! is_array($this->original)) {
            $this->destroyThumbnail();
        }

        return $this;
    }

    protected function readImage(string $path): ImageInterface
    {
        if (! class_exists(ImageManager::class)) {
            throw new AdminException('Install [intervention/image:^4.4] to use image transformations.');
        }
        $size = getimagesize($path);
        abort_unless($size && $size[0] * $size[1] <= (int) config('admin.upload.max_image_pixels', 25000000), 422, 'Image dimensions are too large.');
        $manager = ImageManager::usingDriver(config('admin.upload.image_driver', Driver::class));

        return $manager->decodePath($path);
    }

    protected function applyImageCall(ImageInterface $image, string $method, array $arguments): ImageInterface
    {
        if (in_array($method, ['resize', 'fit', 'widen', 'heighten'], true)) {
            $constraint = new ImageConstraint();
            $callbackIndex = in_array($method, ['widen', 'heighten'], true) ? 1 : 2;
            if (isset($arguments[$callbackIndex]) && is_callable($arguments[$callbackIndex])) {
                $arguments[$callbackIndex]($constraint);
            }
            $width = $method === 'heighten' ? null : ($arguments[0] ?? null);
            $height = $method === 'heighten' ? ($arguments[0] ?? null) : ($method === 'widen' ? null : ($arguments[1] ?? null));
            if ($method === 'fit') {
                $action = $constraint->preventUpsize ? 'coverDown' : 'cover';

                return $image->$action($width, $height ?: $width, $arguments[3] ?? 'center');
            }
            $aspect = $constraint->preserveAspectRatio || in_array($method, ['widen', 'heighten'], true);
            $action = $aspect ? ($constraint->preventUpsize ? 'scaleDown' : 'scale') : ($constraint->preventUpsize ? 'resizeDown' : 'resize');

            return $image->$action($width, $height);
        }
        $method = static::$interventionAlias[$method] ?? $method;
        if (! is_callable([$image, $method])) {
            throw new AdminException("Image method [{$method}] is unavailable in Intervention Image 4. Update the transformation to the v4 API.");
        }
        $result = $image->$method(...$arguments);
        if (! $result instanceof ImageInterface) {
            throw new AdminException("Image method [{$method}] must return an image transformation.");
        }

        return $result;
    }

}
