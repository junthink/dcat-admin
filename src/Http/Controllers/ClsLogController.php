<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Layout\Content;
use Dcat\Admin\LogViewer\ClsLogException;
use Dcat\Admin\LogViewer\ClsLogViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class ClsLogController extends Controller
{
    public function index(Request $request, Content $content, ClsLogViewer $viewer): Response
    {
        abort_unless(config('admin.cls.enabled'), 404);
        $filters = $viewer->defaults();
        $logs = [];
        $hasMore = false;
        $errors = [];
        $status = 200;

        try {
            $filters = $viewer->filters($request->query());
            $result = $viewer->search($filters);
            $logs = $result['logs'];
            $hasMore = $result['has_more'];
        } catch (ValidationException $exception) {
            $filters = array_replace($filters, array_filter($request->only(array_keys($filters)), 'is_scalar'));
            $errors = $exception->validator->errors()->all();
            $status = 422;
        } catch (ClsLogException $exception) {
            $errors = [$exception->getMessage()];
            $status = 503;
        }

        $url = admin_url('cls-logs');
        $previousUrl = $status === 200 && $filters['page'] > 1
            ? $url.'?'.http_build_query(array_replace($filters, ['page' => $filters['page'] - 1])) : null;
        $nextUrl = $status === 200 && $hasMore
            ? $url.'?'.http_build_query(array_replace($filters, ['page' => $filters['page'] + 1])) : null;

        $content->header(trans('admin::cls.title'))->description(trans('admin::cls.description'))
            ->body(view('admin::logs.cls', [
                'filters' => $filters, 'logs' => $logs, 'errors' => $errors,
                'services' => $viewer->services(), 'levels' => ClsLogViewer::LEVELS,
                'timezone' => $viewer->timezone(), 'url' => $url,
                'previousUrl' => $previousUrl, 'nextUrl' => $nextUrl,
            ]));

        return response($content, $status)->header('Cache-Control', 'no-store, private');
    }
}
