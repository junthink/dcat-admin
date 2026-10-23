<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Actions\Action;
use Dcat\Admin\Form\Field\File;
use Dcat\Admin\Http\Actions\Extensions;
use Dcat\Admin\Http\Controllers\ScaffoldController;
use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Models\AdminTablesSeeder;
use Dcat\Admin\Support\Helper;
use Dcat\Admin\Support\LazyRenderable;
use Dcat\Admin\Support\StringOutput;
use Dcat\Admin\Support\WebUploader;
use Dcat\Admin\Traits\InteractsWithApi;
use Dcat\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;

class AdditionalSecurityTest extends TestCase
{
    protected function ordinaryUser(): Administrator
    {
        $user = Administrator::create(['username' => 'ordinary', 'name' => 'Ordinary', 'password' => Hash::make('test-password')]);
        $this->actingAs($user, 'admin');

        return $user;
    }

    public function test_all_extension_actions_deny_ordinary_users(): void
    {
        $this->ordinaryUser();
        foreach ([Extensions\Enable::class, Extensions\Disable::class, Extensions\Update::class, Extensions\Uninstall::class] as $class) {
            $this->post('/admin/dcat-api/action', ['_action' => str_replace('\\', '_', $class), '_key' => 'test/package'])->assertForbidden();
        }
        // Reach the controller even when route-level permission allows this page.
        config(['admin.permission.except' => ['auth/extensions']]);
        $this->get('/admin/auth/extensions')->assertForbidden();
    }

    public function test_valid_handlers_continue_to_work(): void
    {
        $this->ordinaryUser();
        $this->postJson('/admin/dcat-api/action', ['_action' => str_replace('\\', '_', AllowedAction::class)])->assertOk()->assertJson(['accepted' => true]);
        $this->postJson('/admin/dcat-api/form', [Form::REQUEST_NAME => AllowedForm::class])->assertOk()->assertJson(['accepted' => true]);
        $this->postJson('/admin/dcat-api/value', ['_key' => AllowedValue::class])->assertOk()->assertJson(['accepted' => true]);
        $this->get('/admin/dcat-api/render?'.http_build_query(['renderable' => str_replace('\\', '_', AllowedRenderable::class)]))->assertOk()->assertSee('rendered');
    }

    public function test_untrusted_class_names_cannot_reach_autoloading(): void
    {
        foreach (['Dcat\\Admin\\..\\config', 'Foo/Bar', '', ['Foo'], 'Foo\\', 'Foo::bar'] as $name) {
            $this->assertFalse(Helper::validateClassName($name));
        }
        $this->assertTrue(Helper::validateClassName(AllowedForm::class));
    }

    public function test_initial_password_is_not_a_public_default(): void
    {
        $this->seed(AdminTablesSeeder::class);
        $this->assertFalse(Hash::check('admin', Administrator::first()->password));
    }

    public function test_configured_initial_credentials_are_used(): void
    {
        config(['admin.auth.initial_username' => 'first-user', 'admin.auth.initial_password' => 'a-long-initial-password']);
        $this->seed(AdminTablesSeeder::class);
        $this->assertSame('first-user', Administrator::first()->username);
        $this->assertTrue(Hash::check('a-long-initial-password', Administrator::first()->password));
    }

    public function test_logout_is_protected_by_laravel_13_request_forgery_middleware(): void
    {
        $this->ordinaryUser();
        $this->app['env'] = 'local';
        $this->post('/admin/auth/logout')->assertStatus(419);
        $this->assertAuthenticated('admin');
        $this->withSession(['_token' => 'logout-csrf-token'])->post('/admin/auth/logout', ['_token' => 'logout-csrf-token'])->assertRedirect();
        $this->assertGuest('admin');
    }

    public function test_chunk_upload_indices_are_bounded(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        new WebUploader(Request::create('/', 'POST', ['chunks' => 2, 'chunk' => '../../outside']));
    }

    public function test_chunk_uploads_are_isolated_by_session(): void
    {
        $request = Request::create('/', 'POST', ['_id' => 'same-id', 'upload_column' => 'file']);
        $session = $this->app['session']->driver();
        $session->start();
        $request->setLaravelSession($session);
        $first = (new WebUploader($request))->getTemporaryPath('same-id');
        $session->regenerate();
        $second = (new WebUploader($request))->getTemporaryPath('same-id');
        $this->assertNotSame($first, $second);
    }

    public static function dangerousFiles(): array
    {
        return [['test.php'], ['test.php.jpg'], ['test.phtml'], ['test.html'], ['test.svg'], ['.htaccess']];
    }

    #[DataProvider('dangerousFiles')]
    public function test_generic_upload_fields_reject_active_content(string $name): void
    {
        $field = new InspectableUpload('attachment');
        $this->assertNotFalse($field->validationError(UploadedFile::fake()->createWithContent($name, 'test')));
    }

    public function test_upload_accept_is_enforced_on_the_server(): void
    {
        $field = (new InspectableUpload('attachment'))->accept('png,jpg');
        $this->assertNull($field->validationError(UploadedFile::fake()->image('photo.png')));
        $this->assertNotEmpty($field->validationError(UploadedFile::fake()->createWithContent('text.txt', 'test')));
    }

    public function test_active_content_is_rejected_when_filename_is_disguised(): void
    {
        $field = new InspectableUpload('attachment');
        foreach (['<!doctype html><html><script>alert(1)</script></html>', '<?php echo "fixture";'] as $content) {
            $path = tempnam(sys_get_temp_dir(), 'dcat-mime-');
            file_put_contents($path, $content);
            try {
                $file = new UploadedFile($path, 'document.txt', null, null, true);
                $this->assertNotEmpty($field->validationError($file));
            } finally {
                unlink($path);
            }
        }
    }

    public function test_scaffold_uses_bindings_for_table_names(): void
    {
        config(['database.connections.audit' => ['driver' => 'mysql', 'database' => 'example', 'prefix' => 'prefix_']]);
        $connection = \Mockery::mock();
        $table = "users' OR '1'='1";
        $connection->shouldReceive('select')->once()->with(
            'SELECT * FROM information_schema.columns WHERE table_schema = ? AND TABLE_NAME = ? ORDER BY `ORDINAL_POSITION` ASC',
            ['example', 'prefix_'.$table]
        )->andReturn([]);
        $database = DB::getFacadeRoot();
        try {
            DB::shouldReceive('connection')->with('audit')->andReturn($connection);
            $controller = new class extends ScaffoldController {
                public function columns($table) { return $this->getDatabaseColumns('example', $table); }
            };
            $this->assertSame(['example' => []], $controller->columns($table));
        } finally {
            DB::swap($database);
        }
    }

    public function test_console_output_is_compatible_with_symfony(): void
    {
        $output = new StringOutput();
        $output->writeln('compatible');
        $this->assertSame('compatible', $output->getContent());
    }
}

class InspectableUpload extends File
{
    public function validationError($file)
    {
        return $this->getValidationErrors($file);
    }
}

class AllowedAction extends Action
{
    public function handle(Request $request) { return ['accepted' => true]; }
}

class AllowedForm extends Form
{
    public function form() {}
    public function handle(array $input) { return ['accepted' => true]; }
}

class AllowedValue
{
    use InteractsWithApi;
    public function handle(Request $request) { return ['accepted' => true]; }
}

class AllowedRenderable extends LazyRenderable
{
    public function render() { return 'rendered'; }
}
