<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Console\PublishCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PublishingTest extends TestCase
{
    public function test_resource_directory_publication_works_with_flysystem_3(): void
    {
        $directory = sys_get_temp_dir().'/dcat-publish-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/source/en', 0700, true);
        file_put_contents($directory.'/source/en/admin.php', '<?php return ["test" => "published"];');
        $command = $this->app->make(TestPublisher::class);
        $command->source = $directory.'/source';
        $command->target = $directory.'/target';
        $command->setLaravel($this->app);

        try {
            $this->assertSame(0, $command->run(new ArrayInput(['--force' => true]), new BufferedOutput()));
            $this->assertFileEquals($directory.'/source/en/admin.php', $directory.'/target/en/admin.php');
        } finally {
            $this->app['files']->deleteDirectory($directory);
        }
    }
}

class TestPublisher extends PublishCommand
{
    public string $source;
    public string $target;

    public function handle()
    {
        $this->publishDirectory($this->source, $this->target);
    }
}
