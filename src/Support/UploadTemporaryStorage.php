<?php

namespace Dcat\Admin\Support;

use Dcat\Admin\Exception\UploadException;

/** A bounded, process-safe store dedicated to incomplete uploads. */
class UploadTemporaryStorage
{
    public function __construct(protected string $directory)
    {
        abort_if(is_link($directory), 422, 'Invalid temporary upload directory.');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new UploadException('Cannot create temporary upload directory.');
        }
    }

    public function locked(callable $callback)
    {
        $lock = fopen($this->directory.'/.lock', 'c');
        if (! $lock) {
            throw new UploadException('Cannot lock temporary uploads.');
        }
        try {
            $deadline = microtime(true) + 5;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                abort_if(microtime(true) >= $deadline, 429, 'Temporary upload storage is busy. Retry this chunk.');
                usleep(20000);
            }

            return $callback($this);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Must be called while locked. Only upload buckets belonging to this store are touched. */
    public function prune(): int
    {
        $removed = 0;
        $cutoff = time() - max(1, (int) config('admin.upload.chunk_ttl', 3600));
        foreach ($this->buckets() as $directory) {
            if (filemtime($directory.'/.metadata.json') < $cutoff) {
                $this->remove($directory);
                $removed++;
            }
        }

        return $removed;
    }

    public function buckets(): array
    {
        return array_values(array_filter(glob($this->directory.'/*') ?: [], static fn ($path) =>
            preg_match('/\A[a-f0-9]{64}\z/D', basename($path)) && ! is_link($path)
            && is_dir($path) && is_file($path.'/.metadata.json') && ! is_link($path.'/.metadata.json')));
    }

    public function metadata(string $directory): array
    {
        return json_decode(file_get_contents($directory.'/.metadata.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function writeMetadata(string $directory, array $metadata): void
    {
        file_put_contents($directory.'/.metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR), LOCK_EX);
        // Clear stat cache after touch/write in long-lived workers and tests.
        clearstatcache(true, $directory.'/.metadata.json');
    }

    public function bytes(string $directory): int
    {
        $size = 0;
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file) && ! is_link($file)) {
                $size += filesize($file);
            }
        }

        return $size;
    }

    public function assertCapacity(string $owner, string $bucket, int $additionalBytes, bool $new = false): void
    {
        $globalBytes = $ownerBytes = $ownerBuckets = 0;
        foreach ($this->buckets() as $directory) {
            $bytes = $this->bytes($directory);
            $globalBytes += $bytes;
            if (($this->metadata($directory)['owner'] ?? null) === $owner) {
                $ownerBytes += $bytes;
                $ownerBuckets++;
            }
        }
        abort_if($new && $ownerBuckets >= (int) config('admin.upload.max_pending_uploads', 10), 429, 'Too many pending uploads.');
        abort_if($ownerBytes + $additionalBytes > (int) config('admin.upload.owner_temporary_quota', 268435456), 413, 'Temporary upload quota exceeded.');
        abort_if($globalBytes + $additionalBytes > (int) config('admin.upload.temporary_quota', 1073741824), 413, 'Temporary upload storage is full.');
    }

    public function remove(string $directory): void
    {
        if (dirname($directory) !== $this->directory || is_link($directory)) {
            throw new UploadException('Invalid temporary upload bucket.');
        }
        if (is_dir($directory)) {
            app('files')->deleteDirectory($directory);
        }
    }
}
