<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Support\Zip;
use PHPUnit\Framework\Attributes\DataProvider;

class ArchiveSecurityTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/dcat-archive-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->directory);
        parent::tearDown();
    }

    protected function archive(string $name, string $content = 'test'): string
    {
        $path = $this->directory.'/source.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString($name, $content);
        $zip->close();

        return $path;
    }

    public static function unsafeNames(): array
    {
        return [['../outside.txt'], ['/absolute.txt'], ['C:/absolute.txt'], ['dir/../../outside.txt'], ['..\\outside.txt']];
    }

    #[DataProvider('unsafeNames')]
    public function test_archive_paths_are_validated_before_extraction(string $name): void
    {
        $this->assertFalse(Zip::extract($this->archive($name), $this->directory.'/output'));
        $this->assertDirectoryDoesNotExist($this->directory.'/output');
    }

    public function test_normal_archives_extract_and_size_limit_is_enforced(): void
    {
        $path = $this->archive('package/file.txt');
        $this->assertFalse(Zip::extract($path, $this->directory.'/limited', ['max_size' => 1]));
        $this->assertTrue(Zip::extract($path, $this->directory.'/output'));
        $this->assertSame('test', file_get_contents($this->directory.'/output/package/file.txt'));
    }

    public function test_symlink_entries_are_rejected(): void
    {
        $path = $this->archive('link');
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->assertFalse(Zip::extract($path, $this->directory.'/output'));
    }
}
