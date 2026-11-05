<?php

namespace Dcat\Admin\LogViewer;

use Dcat\Admin\Grid;
use Dcat\Admin\Widgets\Alert;
use Dcat\Admin\Widgets\Card;
use Illuminate\Contracts\Support\Renderable;

class ClsLogPage implements Renderable
{
    public function __construct(protected array $data)
    {
    }

    public function render(): string
    {
        $data = $this->data;
        $filters = $data['filters'];
        $grid = new Grid();
        $grid->disableActions();
        $grid->disableRowSelector();
        $grid->disableCreateButton();
        $grid->disableBatchActions();
        $grid->disableColumnSelector();
        $grid->disablePerPages();
        $grid->scrollbarX();

        $size = max(1, min(1000, (int) config('admin.cls.page_size', 100)));
        $grid->paginate($size);
        $grid->simplePaginate();
        $logs = [];
        foreach ($data['logs'] as $index => $log) {
            $logs[] = ['id' => $filters['page'].'-'.$index] + $log;
        }
        $grid->model()->setData(new ClsLogPaginator(
            $logs, $size, (int) $filters['page'], $data['url'], $data['previousUrl'], $data['nextUrl']
        ));

        $grid->filter(function (Grid\Filter $filter) use ($data, $filters) {
            $filter->panel()->expand()->setAction($data['url']);
            // CLS filtering was already validated and applied by ClsLogViewer, not SQL.
            $format = ['format' => 'YYYY-MM-DD HH:mm:ss'];
            $filter->equal('start', trans('admin::cls.start'))
                ->ignore()->datetime($format)->default(str_replace('T', ' ', $filters['start']))->width(6);
            $filter->equal('end', trans('admin::cls.end'))
                ->ignore()->datetime($format)->default(str_replace('T', ' ', $filters['end']))->width(6);
            $filter->equal('service', trans('admin::cls.'.($data['usesLogset'] ? 'application' : 'service')))
                ->ignore()->select($data['services'])
                ->placeholder(trans('admin::cls.'.($data['usesLogset'] ? 'all_applications' : 'all_services')))
                ->default($filters['service'])->width(4);
            $filter->equal('level', trans('admin::cls.level'))
                ->ignore()->select(array_combine(ClsLogViewer::LEVELS, ClsLogViewer::LEVELS))
                ->placeholder(trans('admin::cls.all_levels'))->default($filters['level'])->width(3);
            $filter->equal('keyword', trans('admin::cls.keyword'))
                ->ignore()->placeholder(trans('admin::cls.keyword_placeholder'))->default($filters['keyword'])->width(5);
        });

        if ($data['errors']) {
            return Card::make($grid->renderFilter())->render()
                .Alert::make(implode('<br>', array_map('e', $data['errors'])))->danger()->render();
        }

        $grid->column('time', trans('admin::cls.time'))->display(fn ($value) => e($value))->width(180);
        $grid->column('service', trans('admin::cls.'.($data['usesLogset'] ? 'application' : 'service')))
            ->display(fn ($value) => e($value ?: '—'));
        $grid->column('level', trans('admin::cls.level'))->display(fn ($value) => e($value ?: '—'))
            ->label(['ERROR' => 'danger', 'CRITICAL' => 'danger', 'ALERT' => 'danger', 'EMERGENCY' => 'danger',
                'WARNING' => 'warning', 'NOTICE' => 'info', 'INFO' => 'info', 'default' => 'secondary']);
        $grid->column('message', trans('admin::cls.message'))->display(function ($value, Grid\Column $column) {
            return '<div class="text-break" style="white-space:pre-wrap;min-width:16rem;max-width:70rem">'.e($value).'</div>'
                .'<small class="text-muted">'.e($column->getOriginalModel()['source']).'</small>';
        });
        $grid->column('details', trans('admin::cls.details'))->display(fn () => trans('admin::cls.details'))
            ->expand(function (Grid\Displayers\Expand $expand) {
                return '<pre class="p-2 mb-0" style="max-height:28rem;white-space:pre-wrap;overflow-wrap:anywhere">'.e($expand->row['details']).'</pre>';
            });
        $grid->header(e(trans('admin::cls.count', ['count' => count($logs)])));
        $grid->footer(e(trans('admin::cls.page', ['page' => $filters['page']])));

        return $grid->render();
    }
}
