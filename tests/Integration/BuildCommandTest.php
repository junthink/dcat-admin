<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Console\MinifyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildCommandTest extends TestCase
{
    protected function command(array $arguments): InspectMinifyCommand
    {
        $command = new InspectMinifyCommand();
        $command->setLaravel($this->app);
        $command->status = $command->run(new ArrayInput($arguments), new BufferedOutput());

        return $command;
    }

    public function test_theme_build_uses_argument_arrays_and_leaves_source_files_unchanged(): void
    {
        $source = __DIR__.'/../../resources/assets/dcat/sass/theme/_primary.scss';
        $before = file_get_contents($source);
        $command = $this->command(['name' => 'blue']);
        $this->assertSame(0, $command->status);
        $this->assertSame([['npm', 'run', 'production', '--', '--theme=blue', '--color=#6d8be6']], $command->commands);
        $this->assertSame($before, file_get_contents($source));
    }

    public function test_default_and_all_builds_include_all_bundled_themes(): void
    {
        foreach (['default', 'all'] as $name) {
            $command = $this->command(['name' => $name]);
            $this->assertSame(0, $command->status);
            $this->assertSame([['npm', 'run', 'production', '--']], $command->commands);
        }
    }

    public function test_invalid_names_or_colors_are_rejected_before_running_a_process(): void
    {
        foreach ([['name' => '../outside'], ['name' => 'custom', '--color' => 'red;echo fixture'], ['name' => 'custom', '--color' => '#fff@import']] as $arguments) {
            $command = $this->command($arguments);
            $this->assertSame(1, $command->status);
            $this->assertSame([], $command->commands);
        }
    }
}

class InspectMinifyCommand extends MinifyCommand
{
    public array $commands = [];
    public int $status;
    protected function npmInstall(): int { return 0; }
    protected function runProcess(array $command, int $timeout = 1800): int { $this->commands[] = $command; return 0; }
}
