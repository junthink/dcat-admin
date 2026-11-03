<?php

use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Form;
use Dcat\Admin\Widgets\Markdown;
use Dcat\Admin\Grid;
use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Widgets\Modal;
use Dcat\Admin\Widgets\Tab;
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
    Route::get('regression/overlays/form', function () {
        return view('admin::layouts.form-content', ['content' => (new BrowserOverlayForm())->render()]);
    });
    Route::get('regression/overlays', function (Content $content) {
        Admin::script("$('#open-layer-form').on('click', function () { Dcat.DialogForm({defaultUrl:'/admin/regression/overlays/form?color=' + (new URLSearchParams(location.search).get('color') || ''), title:'Overlay form', area:['900px','700px']}); });");
        return $content->title('Overlay regression')
            ->body('<button id="open-layer-form" class="btn btn-primary">Open Layer form</button>')
            ->body(Modal::make('Editor modal', new BrowserOverlayForm())->xl()->button('<button id="open-editor-modal" class="btn btn-primary">Open editor modal</button>'));
    });
    Route::get('regression/grid', function (Content $content) {
        $grid = Grid::make(new Administrator(), function (Grid $grid) {
            $grid->column('id');
            $grid->column('name')->editable();
            $grid->column('username')->display(fn ($value) => request('scroll') ? str_repeat($value.' ', 12) : $value);
            $grid->column('created_at');
            $grid->scrollbarX((bool) request('scroll'));
            $grid->filter(function ($filter) { $filter->like('name'); $filter->rightSide(); });
            $grid->actions(function ($actions) {
                $actions->append('<a href="#" class="regression-row-action">Test row action</a>');
            });
        });
        Admin::script("$(document).on('click', '.regression-row-action', function (e) {e.preventDefault(); window.rowActionClicked = true;});");
        if (request('modal')) {
            $grid = Modal::make('Grid modal', $grid)->xl()->button('<button id="open-grid-modal" class="btn btn-primary">Open grid modal</button>');
        }
        return $content->title('Grid regression')->body($grid)->body('<div style="height:1100px"></div>');
    });
    Route::get('regression/tabs', function (Content $content) {
        $labels = ['activity' => '活跃日历', 'watch' => '进房日志', 'account' => '收支明细', 'receive' => '收礼记录',
            'give' => '送礼记录', 'prize' => '领奖记录', 'bag' => '背包', 'medal' => '勋章', 'withdraw' => '提现记录',
            'pay' => '充值记录', 'refund' => '退款记录', 'live' => '开播记录', 'snapshot' => '快照', 'record' => '直播录制',
            'pk' => 'PK记录', 'follow' => '关注', 'fans' => '粉丝', 'device' => '设备信息', 'log' => '日志'];
        $active = request('_t', 'receive');
        abort_unless(is_string($active) && isset($labels[$active]), 404);
        $links = [];
        foreach ($labels as $key => $label) {
            $links[$key] = ['title' => $label, 'href' => request()->fullUrlWithQuery(['_t' => $key, 'page' => 1])];
        }
        $tab = Tab::make()->id('linked-tabs')->withCard()->searchable(!request('no_search'))
            ->addLinks($links, $active, fn () => '<div data-loaded-tab="'.e($active).'" style="padding:16px">Selected content: '.e($labels[$active]).'</div>');
        $local = Tab::make()->id('local-tabs')->withCard()->searchable()
            ->add('First panel', 'First local content', true, 'local-first')
            ->add('Second panel', 'Second local content', false, 'local-second');
        if (request('vertical')) $local->vertical()->theme('primary');
        return $content->title('Tab regression')->body($tab)->body($local);
    });
});

class BrowserOverlayForm extends Form
{
    public function form()
    {
        $this->action('regression/save');
        $this->text('title')->default('Overlay form');
        $this->editor('overlay_editor')->height(220)->default('<p>Editable content</p>');
        if (request('color')) {
            $this->color('color')->default('#6d8be6');
        }
    }
}

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
