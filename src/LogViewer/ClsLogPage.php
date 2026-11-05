<?php

namespace Dcat\Admin\LogViewer;

use Dcat\Admin\Admin;
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
            $filter->panel()->expand()->setAction($data['url'])->setFilterID('cls-log-filters');
            // CLS filtering was already validated and applied by ClsLogViewer, not SQL.
            $format = ['format' => 'YYYY-MM-DD HH:mm:ss'];
            $filter->use(new ClsLogTimeRange('time', trans('admin::cls.time_range')))
                ->ignore()->datetime($format)->default([
                    'start' => str_replace('T', ' ', $filters['start']),
                    'end' => str_replace('T', ' ', $filters['end']),
                ])->width(5);
            $filter->equal('service', trans('admin::cls.'.($data['usesLogset'] ? 'application' : 'service')))
                ->ignore()->select($data['services'])
                ->placeholder(trans('admin::cls.'.($data['usesLogset'] ? 'all_applications' : 'all_services')))
                ->default($filters['service'])->width(3);
            $filter->equal('level', trans('admin::cls.level'))
                ->ignore()->select(array_combine(ClsLogViewer::LEVELS, ClsLogViewer::LEVELS))
                ->placeholder(trans('admin::cls.all_levels'))->default($filters['level'])->width(2);
            $filter->equal('keyword', trans('admin::cls.keyword'))
                ->ignore()->placeholder(trans('admin::cls.keyword_placeholder'))->default($filters['keyword'])->width(2);
        });

        Admin::style(<<<'CSS'
#cls-log-filters .grid-filter-form > .row {
    display: grid;
    grid-template-columns: minmax(0, 2.8fr) minmax(0, 1.6fr) minmax(0, 1.1fr) minmax(0, 1.5fr) auto auto;
    gap: .5rem;
    align-items: start;
    margin: 0;
}
#cls-log-filters .filter-input { min-width: 0; width: auto; max-width: none; padding: 0; }
#cls-log-filters .form-group { margin-bottom: 0; }
#cls-log-filters .input-group-prepend { width: 100%; margin: 0; }
#cls-log-filters .input-group-prepend .input-group-text { border: 0; padding: 0 0 .4rem; }
#cls-log-filters .filter-input:first-child input { font-size: 11px; padding-left: .4rem; padding-right: .4rem; }
#cls-log-filters .submit, #cls-log-filters .reset { align-self: end; }
#cls-log-filters .submit, #cls-log-filters .reset { margin-left: 0 !important; white-space: nowrap; }
@media (max-width: 991.98px) {
    #cls-log-filters .grid-filter-form > .row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    #cls-log-filters .filter-input:first-child, #cls-log-filters .filter-input:nth-child(4) { grid-column: 1 / -1; }
}
@media (max-width: 575.98px) {
    #cls-log-filters .filter-input { grid-column: 1 / -1; }
}
CSS);

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
        $grid->footer(e(trans('admin::cls.page', ['page' => $filters['page']])));

        return $grid->render();
    }
}
