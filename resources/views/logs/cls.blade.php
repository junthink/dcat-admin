<div class="card">
    <div class="card-body">
        <form action="{{ $url }}" method="get" role="search">
            <div class="row">
                <div class="form-group col-md-3">
                    <label for="cls-start">{{ trans('admin::cls.start') }} <small class="text-muted">{{ $timezone }}</small></label>
                    <input type="datetime-local" id="cls-start" name="start" class="form-control" value="{{ $filters['start'] }}" step="1" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="cls-end">{{ trans('admin::cls.end') }}</label>
                    <input type="datetime-local" id="cls-end" name="end" class="form-control" value="{{ $filters['end'] }}" step="1" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="cls-service">{{ trans('admin::cls.service') }}</label>
                    <select id="cls-service" name="service" class="form-control">
                        <option value="">{{ trans('admin::cls.all_services') }}</option>
                        @foreach ($services as $value => $label)
                            <option value="{{ $value }}" @selected($filters['service'] === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="cls-level">{{ trans('admin::cls.level') }}</label>
                    <select id="cls-level" name="level" class="form-control">
                        <option value="">{{ trans('admin::cls.all_levels') }}</option>
                        @foreach ($levels as $level)
                            <option value="{{ $level }}" @selected($filters['level'] === $level)>{{ $level }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row align-items-end">
                <div class="form-group col-md-8">
                    <label for="cls-keyword">{{ trans('admin::cls.keyword') }}</label>
                    <input type="search" id="cls-keyword" name="keyword" class="form-control" value="{{ $filters['keyword'] }}" maxlength="500" placeholder="{{ trans('admin::cls.keyword_placeholder') }}">
                </div>
                <div class="form-group col-md-4">
                    <button type="submit" class="btn btn-primary"><i class="feather icon-search" aria-hidden="true"></i> {{ trans('admin::cls.search') }}</button>
                    <a href="{{ $url }}" class="btn btn-outline-secondary ml-1">{{ trans('admin::cls.reset') }}</a>
                </div>
            </div>
        </form>
    </div>
</div>

@if ($errors)
    <div class="alert alert-danger" role="alert">
        @foreach ($errors as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@else
    <div class="card">
        <div class="card-header">
            <span class="text-muted">{{ trans('admin::cls.count', ['count' => count($logs)]) }}</span>
        </div>
        <div class="table-responsive" style="overflow-x:auto">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>{{ trans('admin::cls.time') }}</th>
                    <th>{{ trans('admin::cls.service') }}</th>
                    <th>{{ trans('admin::cls.level') }}</th>
                    <th>{{ trans('admin::cls.message') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    @php
                        $badge = match ($log['level']) {
                            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'danger',
                            'WARNING' => 'warning',
                            'NOTICE', 'INFO' => 'info',
                            default => 'secondary',
                        };
                    @endphp
                    <tr>
                        <td class="text-nowrap"><small>{{ $log['time'] }}</small></td>
                        <td class="text-nowrap">{{ $log['service'] ?: '—' }}</td>
                        <td><span class="badge badge-{{ $badge }}">{{ $log['level'] ?: '—' }}</span></td>
                        <td class="text-break" style="min-width:16rem;max-width:70rem">
                            <div style="white-space:pre-wrap">{{ $log['message'] }}</div>
                            @if ($log['source'])
                                <small class="text-muted">{{ $log['source'] }}</small>
                            @endif
                            <details class="mt-1">
                                <summary class="text-primary">{{ trans('admin::cls.details') }}</summary>
                                <pre class="mt-1 p-2" style="max-height:28rem;white-space:pre-wrap;overflow-wrap:anywhere">{{ $log['details'] }}</pre>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted p-4">{{ trans('admin::cls.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between">
            <span class="text-muted">{{ trans('admin::cls.page', ['page' => $filters['page']]) }}</span>
            <div>
                @if ($previousUrl)
                    <a href="{{ $previousUrl }}" class="btn btn-outline-primary btn-sm" rel="prev">{{ trans('admin::cls.previous') }}</a>
                @endif
                @if ($nextUrl)
                    <a href="{{ $nextUrl }}" class="btn btn-outline-primary btn-sm ml-1" rel="next">{{ trans('admin::cls.next') }}</a>
                @endif
            </div>
        </div>
    </div>
@endif
