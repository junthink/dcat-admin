<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Admin;
use Dcat\Admin\Http\Controllers\HandleActionController;
use Dcat\Admin\Http\Controllers\HandleFormController;
use Dcat\Admin\Http\Controllers\RenderableController;
use Dcat\Admin\Http\Controllers\ValueController;
use Dcat\Admin\Http\Forms\InstallFromLocal;
use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Support\WebUploader;
use Dcat\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SecurityTest extends TestCase
{
    protected function loginAsOrdinaryUser(): Administrator
    {
        $user = Administrator::create([
            'username' => 'editor', 'name' => 'Editor', 'password' => Hash::make('test-password'),
        ]);
        $this->actingAs($user, 'admin');

        return $user;
    }

    public function test_form_upload_checks_authorization_before_building_fields(): void
    {
        $this->loginAsOrdinaryUser();
        $this->post('/admin/dcat-api/form/upload', [Form::REQUEST_NAME => DeniedForm::class])->assertForbidden();
    }

    public function test_form_delete_checks_authorization_before_building_fields(): void
    {
        $this->loginAsOrdinaryUser();
        $this->post('/admin/dcat-api/form/destroy-file', [Form::REQUEST_NAME => DeniedForm::class])->assertForbidden();
    }

    public function test_extension_install_and_render_require_administrator(): void
    {
        $this->loginAsOrdinaryUser();
        $this->post('/admin/dcat-api/form', [Form::REQUEST_NAME => InstallFromLocal::class])->assertForbidden();
        $this->get('/admin/dcat-api/render?'.http_build_query([
            'renderable' => str_replace('\\', '_', InstallFromLocal::class),
        ]))->assertForbidden();
    }

    public static function dynamicControllers(): array
    {
        return [
            [HandleActionController::class, 'handle', '_action'],
            [HandleFormController::class, 'handle', Form::REQUEST_NAME],
            [RenderableController::class, 'handle', 'renderable'],
            [ValueController::class, 'handle', '_key'],
        ];
    }

    #[DataProvider('dynamicControllers')]
    public function test_unrelated_dynamic_class_is_rejected_before_constructor(string $class, string $method, string $key): void
    {
        UnexpectedHandler::$constructed = false;

        try {
            (new $class())->$method(Request::create('/', 'POST', [$key => UnexpectedHandler::class]));
            $this->fail('Unrelated dynamic class was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
        } finally {
            $this->assertFalse(UnexpectedHandler::$constructed, 'Constructor ran before handler type validation.');
        }
    }

    public static function editorEndpoints(): array
    {
        return [
            ['/admin/dcat-api/tinymce/upload', 'file'],
            ['/admin/dcat-api/editor-md/upload', 'editormd-image-file'],
        ];
    }

    #[DataProvider('editorEndpoints')]
    public function test_editor_rejects_executable_upload(string $url, string $field): void
    {
        $this->loginAsOrdinaryUser();
        Storage::fake(config('admin.upload.disk'));
        $this->postJson($url, [$field => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "test";')])
            ->assertUnprocessable();
        $this->assertSame([], Storage::disk(config('admin.upload.disk'))->allFiles());
    }

    #[DataProvider('editorEndpoints')]
    public function test_editor_accepts_image_and_rejects_unconfigured_disk_or_directory(string $url, string $field): void
    {
        $this->loginAsOrdinaryUser();
        Storage::fake(config('admin.upload.disk'));
        $this->postJson($url, [$field => UploadedFile::fake()->image('photo.png')])->assertOk();
        $this->assertCount(1, Storage::disk(config('admin.upload.disk'))->allFiles());
        $this->postJson($url, [$field => UploadedFile::fake()->image('photo.png'), 'disk' => 'local'])->assertUnprocessable();
        $this->postJson($url, [$field => UploadedFile::fake()->image('photo.png'), 'dir' => '../outside'])->assertUnprocessable();
    }

    public function test_chunk_upload_rejects_traversal_before_creating_files(): void
    {
        $uploader = new WebUploader(Request::create('/', 'POST', ['_id' => '../outside']));
        $this->expectException(HttpException::class);
        $uploader->getTemporaryPath('../outside');
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/auth/login', ['username' => 'missing', 'password' => 'wrong']);
        }

        $this->post('/admin/auth/login', ['username' => 'missing', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_logout_requires_post_and_invalidates_authenticated_session(): void
    {
        $this->loginAsOrdinaryUser();
        $this->get('/admin/auth/logout')->assertStatus(405);
        $this->post('/admin/auth/logout')->assertRedirect('/admin/auth/login');
        $this->assertGuest('admin');
    }
}

class DeniedForm extends Form
{
    protected function authorize($user): bool
    {
        return false;
    }

    public function form()
    {
        throw new \LogicException('Denied form must never be built.');
    }

    public function handle(array $input)
    {
        throw new \LogicException('Denied form must never run.');
    }
}

class UnexpectedHandler
{
    public static bool $constructed = false;

    public function __construct()
    {
        static::$constructed = true;
        throw new \LogicException('Unexpected constructor side effect.');
    }
}
