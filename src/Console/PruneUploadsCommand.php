<?php

namespace Dcat\Admin\Console;

use Dcat\Admin\Support\WebUploader;
use Illuminate\Console\Command;

class PruneUploadsCommand extends Command
{
    protected $signature = 'admin:uploads-prune';

    protected $description = 'Remove expired incomplete Dcat Admin uploads';

    public function handle(): int
    {
        $removed = app(WebUploader::class)->pruneTemporaryFiles();
        $this->info("Removed {$removed} expired upload(s).");

        return self::SUCCESS;
    }
}
