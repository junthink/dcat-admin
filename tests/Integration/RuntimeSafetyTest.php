<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Support\DatabaseUpdater;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;

class RuntimeSafetyTest extends TestCase
{
    public function test_failed_migration_restores_mass_assignment_guards_and_rolls_back(): void
    {
        $migration = new class extends Migration {
            public function up(): void
            {
                Administrator::create(['username' => 'rolled-back', 'name' => 'Fixture', 'password' => 'fixture']);
                throw new \RuntimeException('Expected migration failure.');
            }
        };
        $this->assertFalse(Model::isUnguarded());
        try {
            (new DatabaseUpdater())->setUp($migration);
            $this->fail('Migration did not fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Expected migration failure.', $e->getMessage());
        }
        $this->assertFalse(Model::isUnguarded());
        $this->assertSame(0, Administrator::count());
    }

    public function test_anonymous_migrations_can_be_loaded_repeatedly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dcat-migration-');
        file_put_contents($path, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void {} public function down(): void {} };');
        try {
            $updater = new DatabaseUpdater();
            $first = $updater->resolve($path);
            $this->assertInstanceOf(Migration::class, $first);
            $this->assertInstanceOf(Migration::class, (new DatabaseUpdater())->resolve($path));
            $this->assertNotSame($first, $updater->resolve($path));
            $this->assertTrue($updater->setUp($path));
            $this->assertTrue($updater->packDown($path));
        } finally {
            unlink($path);
        }
    }

    public function test_temporary_upload_lock_serializes_independent_processes(): void
    {
        $directory = sys_get_temp_dir().'/dcat-process-lock-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/counter', '0');
        $autoload = var_export(realpath(__DIR__.'/../../vendor/autoload.php'), true);
        $worker = $directory.'/worker.php';
        file_put_contents($worker, '<?php require '.$autoload.'; $store = new \\Dcat\\Admin\\Support\\UploadTemporaryStorage($argv[1]); for ($i=0;$i<3;$i++) { $store->locked(function () use ($argv) { $path=$argv[1]."/counter"; $value=(int)file_get_contents($path); usleep(30000); file_put_contents($path, (string)($value+1)); }); }');
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                $processes[] = proc_open([PHP_BINARY, $worker, $directory], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/stdout-'.$i, 'w'], 2 => ['file', $directory.'/stderr-'.$i, 'w']], $pipes);
            }
            foreach ($processes as $index => $process) {
                $this->assertSame(0, proc_close($process), file_get_contents($directory.'/stderr-'.$index));
            }
            $this->assertSame('6', file_get_contents($directory.'/counter'));
        } finally {
            $this->app['files']->deleteDirectory($directory);
        }
    }
}
