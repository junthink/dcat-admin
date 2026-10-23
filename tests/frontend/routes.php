<?php

use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Form;
use Dcat\Admin\Widgets\Markdown;
use Illuminate\Support\Facades\Route;

Admin::routes();

Route::middleware('admin')->prefix('admin')->group(function () {
    Route::get('/', fn () => redirect('/admin/auth/users'));
    Route::get('favicon.ico', fn () => response('', 204));
    Route::get('regression', function (Content $content) {
        $form = new BrowserWidgetsForm();
        $payload = "# Safe heading\n\n| Col | Value |\n|---|---|\n| One | **two** |\n\n- [x] task\n\n```php\necho 1;\n```\n\n</textarea><img src=x onerror=\"window.__xss=1\"><script>window.__xss=2</script>\n\n[unsafe](javascript:window.__xss=3)";

        return $content->title('Regression widgets')->body($form)->body(Markdown::make($payload));
    });
    Route::post('regression/save', fn () => response()->json(['status' => true, 'data' => ['message' => 'Saved']]));
});

class BrowserWidgetsForm extends Form
{
    public function form()
    {
        $this->action('/admin/regression/save');
        $this->text('title')->required();
        $this->editor('richtext')->default('<p>Rich text fixture</p>');
        $this->markdown('markdown')->default("# Editor heading\n\n**bold**\n\n- [x] task\n");
        $this->date('published_at')->default('2026-10-23');
        $this->select('status')->options(['draft' => 'Draft', 'ready' => 'Ready'])->default('draft');
        $this->file('attachment')->autoUpload();
        $this->image('image')->autoUpload();
        $this->disableResetButton();
    }

    public function handle(array $input)
    {
        return $this->response()->success('Saved');
    }
}
