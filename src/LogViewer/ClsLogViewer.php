<?php

namespace Dcat\Admin\LogViewer;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClsLogViewer
{
    public const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function __construct(protected ClsClient $client)
    {
    }

    public function services(): array
    {
        if ($this->usesLogset()) {
            return $this->client->topics();
        }
        $services = (array) config('admin.cls.services', []);

        return array_is_list($services) ? array_combine($services, $services) : $services;
    }

    public function usesLogset(): bool
    {
        return $this->client->usesLogset();
    }

    public function timezone(): string
    {
        return config('admin.cls.timezone') ?: config('app.timezone', 'UTC');
    }

    public function defaults(): array
    {
        $end = Carbon::now($this->timezone())->startOfSecond();

        return [
            'start' => $end->copy()->subHour()->format('Y-m-d\TH:i:s'),
            'end' => $end->format('Y-m-d\TH:i:s'),
            'service' => '', 'level' => '', 'keyword' => '', 'page' => 1, 'cursor' => '',
        ];
    }

    public function filters(array $input): array
    {
        $filters = array_replace($this->defaults(), Arr::only($input, array_keys($this->defaults())));
        $validator = Validator::make($filters, [
            'start' => ['required', 'date_format:Y-m-d\TH:i:s,Y-m-d\TH:i,Y-m-d H:i:s,Y-m-d H:i'],
            'end' => ['required', 'date_format:Y-m-d\TH:i:s,Y-m-d\TH:i,Y-m-d H:i:s,Y-m-d H:i', 'after:start'],
            'service' => ['nullable', 'string', Rule::in(array_keys($this->services()))],
            'level' => ['nullable', 'string', Rule::in(self::LEVELS)],
            'keyword' => ['nullable', 'string', 'max:500', 'not_regex:/[\x00-\x1f\x7f]/u'],
            'page' => ['required', 'integer', 'min:1', 'max:10000'],
            'cursor' => ['nullable', 'string', 'size:64', 'regex:/^[a-zA-Z0-9]+$/D'],
        ], [], [
            'start' => trans('admin::cls.start'), 'end' => trans('admin::cls.end'),
            'service' => trans('admin::cls.'.($this->usesLogset() ? 'application' : 'service')), 'level' => trans('admin::cls.level'),
            'keyword' => trans('admin::cls.keyword'),
        ]);
        $validator->after(function ($validator) use ($filters) {
            if ($validator->errors()->has('start') || $validator->errors()->has('end')) {
                return;
            }
            $start = Carbon::parse($filters['start'], $this->timezone());
            $end = Carbon::parse($filters['end'], $this->timezone());
            $hours = max(1, (int) config('admin.cls.max_range_hours', 168));
            if ($start->diffInSeconds($end) > $hours * 3600) {
                $validator->errors()->add('end', trans('admin::cls.range_too_large', ['hours' => $hours]));
            }
        });

        $validated = $validator->validate();
        foreach (['service', 'level', 'keyword', 'cursor'] as $key) {
            $validated[$key] = $validated[$key] ?? '';
        }
        $validated['page'] = (int) $validated['page'];

        return $validated;
    }

    public function search(array $filters): array
    {
        $conditions = [];
        foreach (['service', 'level'] as $name) {
            if ($name === 'service' && $this->usesLogset()) {
                continue;
            }
            if (($filters[$name] ?? '') === '') {
                continue;
            }
            $field = config('admin.cls.'.$name.'_field', $name === 'service' ? '__TAG__.container_name' : 'level_name');
            if (! is_string($field) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/D', $field)) {
                throw ClsLogException::failure('invalid_filter_field_configuration', ['filter' => $name], 'not_configured');
            }
            $value = $name === 'level'
                ? (config('admin.cls.level_values', [])[$filters['level']] ?? $filters['level'])
                : $filters['service'];
            $conditions[] = $field.':'.$this->quote((string) $value);
        }
        if (($filters['keyword'] ?? '') !== '') {
            $conditions[] = $this->quote($filters['keyword']);
        }
        $size = max(1, min(1000, (int) config('admin.cls.page_size', 100)));
        $parameters = [
            'From' => Carbon::parse($filters['start'], $this->timezone())->getTimestampMs(),
            'To' => Carbon::parse($filters['end'], $this->timezone())->getTimestampMs(),
            'QueryString' => $conditions ? implode(' AND ', $conditions) : '*',
            'QuerySyntax' => 1, 'Sort' => 'desc', 'Limit' => $size,
        ];
        if ($this->usesLogset()) {
            $topics = $this->services();
            if (($filters['service'] ?? '') !== '') {
                if (! array_key_exists($filters['service'], $topics)) {
                    throw ClsLogException::failure('application_not_in_configured_scope', [], 'not_configured');
                }
                $topics = [$filters['service'] => $topics[$filters['service']]];
            }
            $result = (new ClsLogPager($this->client))->search($parameters, $topics, $filters);
            $result['logs'] = array_map(fn (array $log) => $this->normalize($log), $result['results']);
            unset($result['results']);

            return $result;
        }
        $parameters['Offset'] = ((int) $filters['page'] - 1) * $size;
        $result = $this->client->search($parameters);

        return [
            'logs' => array_map(fn (array $log) => $this->normalize($log), $result['Results'] ?? []),
            'has_more' => ! $result['ListOver'],
        ];
    }

    protected function quote(string $value): string
    {
        // Keywords are always one quoted phrase, never user-supplied CQL or SQL.
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    protected function normalize(array $log): array
    {
        $raw = ! empty($log['LogJson']) ? $log['LogJson'] : ($log['RawLog'] ?? '');
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : [];
        $service = $this->field($data, config('admin.cls.service_field', '__TAG__.container_name'));
        if ($this->usesLogset()) {
            $service = $this->services()[$log['TopicId'] ?? ''] ?? '';
        }
        $level = $this->field($data, config('admin.cls.level_field', 'level_name'));
        $levelMap = config('admin.cls.level_values', []);
        $level = array_search($level, $levelMap, true) ?: $level;
        if (is_int($level) || (is_string($level) && ctype_digit($level))) {
            $level = [100 => 'DEBUG', 200 => 'INFO', 250 => 'NOTICE', 300 => 'WARNING', 400 => 'ERROR', 500 => 'CRITICAL', 550 => 'ALERT', 600 => 'EMERGENCY'][(int) $level] ?? $level;
        }
        $message = $this->field($data, config('admin.cls.message_field', 'message'));
        $message = $message ?? $data['content'] ?? $data['log'] ?? $raw;

        return [
            'time' => isset($log['Time']) ? Carbon::createFromTimestampMs($log['Time'], $this->timezone())->format('Y-m-d H:i:s.v') : '',
            'service' => is_scalar($service) ? (string) $service : '',
            'level' => is_scalar($level) ? strtoupper((string) $level) : '',
            'message' => is_scalar($message) ? (string) $message : json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'details' => $data ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw,
            'source' => $log['HostName'] ?? $log['Source'] ?? '',
        ];
    }

    protected function field(array $data, string $field)
    {
        return array_key_exists($field, $data) ? $data[$field] : Arr::get($data, $field);
    }
}
