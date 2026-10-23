<?php

namespace Dcat\Admin\Support;

use Illuminate\Http\Request;
use Dcat\Admin\Admin;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * WebUploader文件上传处理.
 *
 * @property string $_id
 * @property int $chunk
 * @property int $chunks
 * @property string $upload_column
 * @property UploadedFile $file
 */
class WebUploader
{
    const FILE_NAME = '_file_';

    public $_id;

    public $chunk;

    public $chunks;

    public $upload_column;

    public $file;

    public $temporaryDirectory = 'tmp/dcat-uploads';

    protected $owner;

    protected $uploadScope;

    protected $temporaryFilePath;

    protected $completeFile;

    public function __construct(?Request $request = null)
    {
        $request = $this->prepareRequest($request ?: request());

        $this->_id = $request->get('_id');
        $this->chunk = $request->get('chunk');
        $this->chunks = $request->get('chunks');
        $this->upload_column = $request->get('upload_column');
        abort_unless($this->upload_column === null || is_string($this->upload_column), 422, 'Invalid upload column.');
        $this->file = $request->file(static::FILE_NAME);
        $this->owner = hash('sha256', Admin::app()->getName().'|'.(Admin::user() ? 'user:'.Admin::user()->getAuthIdentifier() : 'session:'.($request->hasSession() ? $request->session()->getId() : $request->ip())));
        $this->uploadScope = hash('sha256', $this->owner.'|'.(string) $this->upload_column);

        if ($this->chunks !== null) {
            $chunks = filter_var($this->chunks, FILTER_VALIDATE_INT);
            $chunk = filter_var($this->chunk, FILTER_VALIDATE_INT);
            abort_unless(
                $chunks !== false && $chunks >= 1 && $chunks <= (int) config('admin.upload.max_chunks', 1000)
                && $chunk !== false && $chunk >= 0 && $chunk < $chunks,
                422,
                'Invalid upload chunks.'
            );
            $this->chunks = $chunks;
            $this->chunk = $chunk;
        }
    }

    protected function prepareRequest($request)
    {
        $relation = $request->get('_relation');
        if (! $relation || ! is_string($relation)) {
            return $request;
        }

        return $request->merge([
            '_relation' => trim($relation, ','),
        ]);
    }

    /**
     * 判断是否是分块上传.
     *
     * @return bool
     */
    public function hasChunkFile()
    {
        return $this->chunks > 1;
    }

    /**
     * 判断是否是文件上传请求.
     *
     * @return bool
     */
    public function isUploading()
    {
        $file = $this->file;

        if (
            ! $file
            || ! $this->upload_column
            || ! $file instanceof UploadedFile
        ) {
            return false;
        }

        return true;
    }

    /**
     * 获取完整的上传文件.
     *
     * @return UploadedFile|void
     */
    public function getUploadedFile()
    {
        $file = $this->file;

        if (! $file || ! $file instanceof UploadedFile) {
            return;
        }

        if (! $this->hasChunkFile()) {
            return $file;
        }

        if ($this->completeFile !== null) {
            return $this->completeFile;
        }

        return $this->completeFile = $this->mergeChunks($file);
    }

    /**
     * 移除临时文件以及文件夹.
     */
    public function deleteTemporaryFile()
    {
        if (! $this->temporaryFilePath) {
            return;
        }
        $store = $this->temporaryStorage();
        $store->locked(fn () => $store->remove(dirname($this->temporaryFilePath)));
        $this->temporaryFilePath = null;
    }

    public function pruneTemporaryFiles(): int
    {
        $store = $this->temporaryStorage();

        return $store->locked(fn () => $store->prune());
    }

    protected function temporaryStorage(): UploadTemporaryStorage
    {
        return new UploadTemporaryStorage($this->getTemporaryDirectory());
    }

    /**
     * 合并分块文件.
     *
     * @param  UploadedFile  $file
     * @return UploadedFile|false
     */
    protected function mergeChunks(UploadedFile $file)
    {
        $store = $this->temporaryStorage();

        return $store->locked(function () use ($store, $file) {
            $store->prune();
            $tmpDir = $this->getTemporaryPath($this->_id);
            abort_if(is_link($tmpDir), 422, 'Invalid temporary upload bucket.');
            $newFilename = $this->generateChunkFileName($file);
            $new = ! is_dir($tmpDir);
            $existing = $new ? [] : $store->metadata($tmpDir);
            abort_if($existing && (($existing['chunks'] ?? null) !== $this->chunks || ($existing['filename'] ?? null) !== $newFilename), 422, 'Upload metadata changed.');
            abort_if(! empty($existing['complete']), 409, 'This upload is already complete.');
            $part = $tmpDir.'/'.$newFilename.'.'.$this->chunk.'.part';
            $additional = $file->getSize() - (is_file($part) ? filesize($part) : 0);
            $store->assertCapacity($this->owner, $tmpDir, $additional, $new);
            if ($new) {
                mkdir($tmpDir, 0700);
            }
            $metadata = ['owner' => $this->owner, 'chunks' => $this->chunks, 'filename' => $newFilename, 'complete' => false];
            $store->writeMetadata($tmpDir, $metadata);
            $this->moveChunk($file, $tmpDir, $newFilename);
            if (! $this->isComplete($tmpDir, $newFilename)) {
                return false;
            }
            // Reserve enough room for the merged file before allocating it.
            $store->assertCapacity($this->owner, $tmpDir, $store->bytes($tmpDir));
            $this->temporaryFilePath = $tmpDir.'/'.$newFilename.'.tmp';
            $this->putTempFileContent($this->temporaryFilePath, $tmpDir, $newFilename);
            $metadata['complete'] = true;
            $store->writeMetadata($tmpDir, $metadata);

            return new UploadedFile($this->temporaryFilePath, $file->getClientOriginalName(), null, null, true);
        });
    }

    /**
     * 判断所有分块是否上传完毕.
     *
     * @param  string  $tmpDir
     * @param  string  $newFilename
     * @return bool
     */
    protected function isComplete($tmpDir, $newFilename)
    {
        for ($index = 0; $index < $this->chunks; $index++) {
            if (! is_file("{$tmpDir}/{$newFilename}.{$index}.part")) {
                return false;
            }
        }

        return true;
    }

    /**
     * 移动分块文件到临时目录.
     *
     * @param  UploadedFile  $file
     * @param  string  $tmpDir
     * @param  string  $newFilename
     */
    protected function moveChunk(UploadedFile $file, $tmpDir, $newFilename)
    {
        abort_if($file->getSize() > (int) config('admin.upload.chunk_size', 10485760), 422, 'Upload chunk is too large.');
        $size = $file->getSize();
        foreach (glob($tmpDir.'/'.$newFilename.'.*.part') ?: [] as $part) {
            if ($part !== $tmpDir.'/'.$newFilename.'.'.$this->chunk.'.part') {
                $size += filesize($part);
            }
        }
        abort_if($size > (int) config('admin.upload.max_chunked_size', 104857600), 413, 'Chunked upload is too large.');
        $file->move($tmpDir, "{$newFilename}.{$this->chunk}.part");
    }

    /**
     * @param  string  $path
     * @param  string  $tmpDir
     * @param  string  $newFilename
     */
    protected function putTempFileContent($path, $tmpDir, $newFileame)
    {
        $out = fopen($path, 'xb');
        if (! $out) {
            throw new \RuntimeException('Cannot create merged upload.');
        }
        try {
            for ($index = 0; $index < $this->chunks; $index++) {
                $partPath = "{$tmpDir}/{$newFileame}.{$index}.part";
                $in = fopen($partPath, 'rb');
                if (! $in) {
                    throw new \RuntimeException('Missing upload chunk.');
                }
                try {
                    $expected = filesize($partPath);
                    if (stream_copy_to_stream($in, $out) !== $expected) {
                        throw new \RuntimeException('Cannot merge upload chunk.');
                    }
                } finally {
                    fclose($in);
                }
            }
        } catch (\Throwable $exception) {
            unlink($path);
            throw $exception;
        } finally {
            fclose($out);
        }
        for ($index = 0; $index < $this->chunks; $index++) {
            unlink("{$tmpDir}/{$newFileame}.{$index}.part");
        }
    }

    /**
     * 生成分块文件名称.
     *
     * @param  UploadedFile  $file
     * @return string
     */
    protected function generateChunkFileName(UploadedFile $file)
    {
        return md5($file->getClientOriginalName());
    }

    /**
     * 获取临时文件路径.
     *
     * @param  mixed  $path
     * @return string
     */
    public function getTemporaryPath($path)
    {
        abort_unless(is_string($path) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $path), 422, 'Invalid upload id.');

        return $this->getTemporaryDirectory().'/'.hash('sha256', $this->uploadScope.'|'.$path);
    }

    /**
     * 获取临时文件目录.
     *
     * @return string
     */
    public function getTemporaryDirectory()
    {
        $dir = storage_path($this->temporaryDirectory);

        if (! is_dir($dir)) {
            app('files')->makeDirectory($dir, 0700, true);
        }

        return rtrim($dir, '/');
    }
}
