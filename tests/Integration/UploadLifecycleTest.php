<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Form\Field\File;
use Dcat\Admin\Form\Field\Image;
use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Support\UploadOwnership;
use Dcat\Admin\Support\WebUploader;
use Dcat\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadLifecycleTest extends TestCase
{
    protected string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = 'tmp/dcat-test-'.bin2hex(random_bytes(8));
        $this->actingAs(Administrator::create(['username' => 'owner', 'name' => 'Owner', 'password' => 'test-only']), 'admin');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory(storage_path($this->temporary));
        parent::tearDown();
    }

    protected function chunk(int $index, string $content = 'part', string $id = 'first', int $chunks = 2): WebUploader
    {
        $request = Request::create('/', 'POST', ['_id' => $id, 'chunk' => $index, 'chunks' => $chunks, 'upload_column' => 'file'], [], ['_file_' => UploadedFile::fake()->createWithContent('joined.txt', $content)]);
        $uploader = new WebUploader($request);
        $uploader->temporaryDirectory = $this->temporary;

        return $uploader;
    }

    public function test_out_of_order_and_retried_chunks_merge_exactly_once_and_cleanup(): void
    {
        $this->assertFalse($this->chunk(1, 'world')->getUploadedFile());
        $this->assertFalse($this->chunk(1, 'world')->getUploadedFile());
        $last = $this->chunk(0, 'hello ');
        $file = $last->getUploadedFile();
        $this->assertSame('hello world', file_get_contents($file->getRealPath()));
        $this->assertSame($file, $last->getUploadedFile());
        try {
            $this->chunk(0, 'overwritten')->getUploadedFile();
            $this->fail('Completed upload was overwritten.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $last->deleteTemporaryFile();
        $this->assertFileDoesNotExist($file->getPathname());
        $this->assertDirectoryDoesNotExist(dirname($file->getPathname()));
    }

    public function test_pending_upload_count_is_bounded(): void
    {
        config(['admin.upload.max_pending_uploads' => 1]);
        $this->chunk(0)->getUploadedFile();
        $this->expectException(HttpException::class);
        $this->chunk(0, 'part', 'second')->getUploadedFile();
    }

    public function test_owner_temporary_quota_is_enforced(): void
    {
        config(['admin.upload.owner_temporary_quota' => 6]);
        $this->chunk(0, '1234')->getUploadedFile();
        $this->expectException(HttpException::class);
        $this->chunk(0, '5678', 'second')->getUploadedFile();
    }

    public function test_global_temporary_quota_is_enforced(): void
    {
        config(['admin.upload.temporary_quota' => 6]);
        $this->chunk(0, '1234')->getUploadedFile();
        $this->expectException(HttpException::class);
        $this->chunk(0, '5678', 'second')->getUploadedFile();
    }

    public function test_metadata_changes_and_oversized_chunks_are_rejected(): void
    {
        $this->chunk(0)->getUploadedFile();
        try {
            $this->chunk(1, 'part', 'first', 3)->getUploadedFile();
            $this->fail('Changed metadata accepted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        config(['admin.upload.chunk_size' => 3]);
        $this->expectException(HttpException::class);
        $this->chunk(0, 'oversized', 'second')->getUploadedFile();
    }

    public function test_expiry_prunes_only_owned_upload_buckets(): void
    {
        $uploader = $this->chunk(0);
        $uploader->getUploadedFile();
        $bucket = $uploader->getTemporaryPath('first');
        touch($bucket.'/.metadata.json', time() - 7200);
        mkdir(storage_path($this->temporary).'/unrelated');
        file_put_contents(storage_path($this->temporary).'/unrelated/keep.txt', 'keep');
        $this->assertSame(1, $uploader->pruneTemporaryFiles());
        $this->assertDirectoryDoesNotExist($bucket);
        $this->assertFileExists(storage_path($this->temporary).'/unrelated/keep.txt');
    }

    public function test_delete_endpoint_rejects_another_users_file_and_accepts_own_upload(): void
    {
        Storage::disk('public')->put('files/private.txt', 'victim');
        $parameters = [Form::REQUEST_NAME => LifecycleForm::class, '_column' => 'attachment', 'key' => 'files/private.txt'];
        $this->postJson('/admin/dcat-api/form/destroy-file', $parameters)->assertForbidden();
        Storage::disk('public')->assertExists('files/private.txt');
        UploadOwnership::record('public', 'files/private.txt');
        $this->postJson('/admin/dcat-api/form/destroy-file', $parameters)->assertOk();
        Storage::disk('public')->assertMissing('files/private.txt');
    }

    public function test_upload_claim_does_not_transfer_to_another_user(): void
    {
        Storage::disk('public')->put('files/pending.txt', 'pending');
        UploadOwnership::record('public', 'files/pending.txt');
        $this->actingAs(Administrator::create(['username' => 'other', 'name' => 'Other', 'password' => 'test-only']), 'admin');
        $this->postJson('/admin/dcat-api/form/destroy-file', [Form::REQUEST_NAME => LifecycleForm::class, '_column' => 'attachment', 'key' => 'files/pending.txt'])->assertForbidden();
        Storage::disk('public')->assertExists('files/pending.txt');
    }

    public function test_server_loaded_original_may_be_deleted_after_claim_expires(): void
    {
        $field = new File('attachment');
        Storage::disk('public')->put('files/existing.txt', 'existing');
        $field->setOriginal(['attachment' => 'files/existing.txt']);
        $field->assertCanDeleteFiles('files/existing.txt');
        $field->deleteFile('files/existing.txt');
        Storage::disk('public')->assertMissing('files/existing.txt');
    }

    public function test_image_4_transformations_and_thumbnails_are_persisted(): void
    {
        $this->app['request']->merge(['_id' => 'image-test']);
        $field = (new Image('avatar'))->uniqueName()->fit(40, 30)->thumbnail('small', 20, 10);
        $field->upload(UploadedFile::fake()->image('photo.png', 100, 80));
        $files = Storage::disk('public')->allFiles('images');
        $this->assertCount(2, $files);
        foreach ($files as $path) {
            [$width, $height] = getimagesize(Storage::disk('public')->path($path));
            if (str_contains($path, '-small.')) {
                $this->assertLessThanOrEqual(20, $width);
                $this->assertLessThanOrEqual(10, $height);
            } else {
                $this->assertSame([40, 30], [$width, $height]);
                $this->assertTrue(UploadOwnership::owns('public', $path));
            }
        }
    }

    public function test_default_editor_upload_urls_follow_the_configured_image_directory(): void
    {
        config(['admin.upload.directory.image' => 'media/images']);
        $editor = new class('content') extends \Dcat\Admin\Form\Field\Editor {
            public function uploadAddress() { return $this->defaultImageUploadUrl(); }
        };
        $markdown = new class('content') extends \Dcat\Admin\Form\Field\Markdown {
            public function uploadAddress() { return $this->defaultImageUploadUrl(); }
        };
        $this->assertStringContainsString('dir=media%2Fimages%2Ftinymce', $editor->uploadAddress());
        $this->assertStringContainsString('dir=media%2Fimages%2Fmarkdown', $markdown->uploadAddress());
    }

    public function test_legacy_aspect_ratio_and_no_upsize_callbacks_work_with_image_4(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 100, 80);
        $field = (new Image('avatar'))->resize(200, 200, function ($constraint) { $constraint->aspectRatio(); $constraint->upsize(); });
        $field->callInterventionMethods($file->getRealPath(), 'image/png');
        $this->assertSame([100, 80], array_slice(getimagesize($file->getRealPath()), 0, 2));
    }
}

class LifecycleForm extends Form
{
    public function form()
    {
        $this->file('attachment');
    }

    public function handle(array $input)
    {
        return $this->response()->success('ok');
    }
}
