<?php

namespace Dcat\Admin\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MinifyCommand extends Command
{
    const ALL = 'all';
    const DEFAULT = 'default';

    protected $signature = 'admin:minify {name} {--color= : Theme hex color} {--publish : Publish assets files}';

    protected $description = 'Build CSS and JavaScript using the locked frontend toolchain';

    protected $colors = ['blue' => '#6d8be6', 'blue-light' => '#62a8ea', 'green' => '#4e9876'];

    protected $packagePath;

    public function handle(): int
    {
        $this->packagePath = realpath(__DIR__.'/../..');
        $name = $this->argument('name');
        if (! is_string($name) || ! preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $name)) {
            $this->error('Invalid theme name. Use lowercase letters, digits and hyphens.');

            return self::FAILURE;
        }
        $arguments = ['npm', 'run', 'production', '--'];
        if (! in_array($name, [static::ALL, static::DEFAULT], true)) {
            $color = $this->option('color') ?: ($this->colors[$name] ?? $this->ask('Please enter a theme color (hex)'));
            if (! is_string($color) || ! preg_match('/\A#?[0-9a-f]{3}(?:[0-9a-f]{3})?\z/iD', $color)) {
                $this->error('Invalid hex color.');

                return self::FAILURE;
            }
            $arguments[] = '--theme='.$name;
            $arguments[] = '--color=#'.ltrim($color, '#');
        }
        if ($this->npmInstall() !== self::SUCCESS || $this->runProcess($arguments) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->option('publish')) {
            return $this->call('vendor:publish', ['--provider' => 'Dcat\Admin\AdminServiceProvider', '--force' => true, '--tag' => 'dcat-admin-assets']);
        }

        return self::SUCCESS;
    }

    protected function npmInstall(): int
    {
        if (is_dir($this->packagePath.'/node_modules')) {
            return self::SUCCESS;
        }

        return $this->runProcess(['npm', 'ci']);
    }

    protected function runProcess(array $command, int $timeout = 1800): int
    {
        $process = new Process($command, $this->packagePath, null, null, $timeout);

        return $process->run(function ($type, $data) {
            if ($type === Process::ERR) {
                $this->warn($data);
            } else {
                $this->output->write($data);
            }
        });
    }
}
