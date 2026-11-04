<?php

namespace Dcat\Admin\Console;

use Dcat\Admin\LogViewer\ClsLogMenu;
use Illuminate\Console\Command;

class ClsInstallCommand extends Command
{
    protected $signature = 'admin:cls-install';

    protected $description = 'Create the CLS log menu and permission without granting additional roles';

    public function handle(): int
    {
        app(ClsLogMenu::class)->install();
        $this->info('CLS menu and GET permission created. Assign the cls-logs permission to the required roles.');

        return self::SUCCESS;
    }
}
