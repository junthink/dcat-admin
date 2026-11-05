<?php

namespace Dcat\Admin\LogViewer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClsClient
{
    private const HOST = 'cls.tencentcloudapi.com';

    private ?array $topics = null;

    public function usesLogset(): bool
    {
        return filled(config('admin.cls.logset_id')) || filled(config('admin.cls.logset_name'));
    }

    /** All pages, scoped to exactly one server-configured logset. */
    public function topics(): array
    {
        if ($this->topics !== null) {
            return $this->topics;
        }
        $logsetId = config('admin.cls.logset_id');
        if (! filled($logsetId)) {
            $name = config('admin.cls.logset_name');
            if (! is_string($name) || trim($name) === '') {
                throw new ClsLogException(trans('admin::cls.not_configured'));
            }
            $matches = array_filter($this->listing('DescribeLogsets', 'Logsets', 'logsetName', $name),
                fn ($item) => ($item['LogsetName'] ?? null) === $name);
            if (count($matches) !== 1 || ! is_string($logsetId = reset($matches)['LogsetId'] ?? null) || $logsetId === '') {
                throw new ClsLogException(trans('admin::cls.logset_not_found'));
            }
        }
        if (! is_string($logsetId)) {
            throw new ClsLogException(trans('admin::cls.not_configured'));
        }
        $topics = [];
        foreach ($this->listing('DescribeTopics', 'Topics', 'logsetId', $logsetId) as $topic) {
            if (($topic['LogsetId'] ?? null) !== $logsetId || ! is_string($topic['TopicId'] ?? null)
                || $topic['TopicId'] === '' || ! is_string($topic['TopicName'] ?? null)) {
                throw new ClsLogException(trans('admin::cls.unavailable'));
            }
            $topics[$topic['TopicId']] = $topic['TopicName'];
        }
        asort($topics, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->topics = $topics;
    }

    protected function listing(string $action, string $key, string $filter, string $value): array
    {
        $items = [];
        do {
            $result = $this->request($action, [
                'Filters' => [['Key' => $filter, 'Values' => [$value]]],
                'Offset' => count($items), 'Limit' => 100,
            ]);
            if (! is_array($result[$key] ?? null) || ! array_is_list($result[$key])
                || ! is_int($result['TotalCount'] ?? null) || $result['TotalCount'] < 0
                || (count($items) < $result['TotalCount'] && $result[$key] === [])) {
                throw new ClsLogException(trans('admin::cls.unavailable'));
            }
            foreach ($result[$key] as $item) {
                if (! is_array($item)) {
                    throw new ClsLogException(trans('admin::cls.unavailable'));
                }
                $items[] = $item;
            }
        } while (count($items) < $result['TotalCount']);

        return $items;
    }

    public function search(array $parameters): array
    {
        return $this->searchTopic($parameters, config('admin.cls.topic_id'));
    }

    public function searchTopic(array $parameters, ?string $topicId): array
    {
        if (! filled($topicId) || ($this->usesLogset()
            ? ! array_key_exists($topicId, $this->topics()) : $topicId !== config('admin.cls.topic_id'))) {
            throw new ClsLogException(trans('admin::cls.not_configured'));
        }
        // Neither raw TopicId nor Topics from a caller can widen the configured scope.
        unset($parameters['Topics']);
        $parameters['TopicId'] = $topicId;
        $result = $this->request('SearchLog', $parameters);
        if (! array_key_exists('Results', $result) || ! is_bool($result['ListOver'] ?? null)
            || ($result['Results'] !== null && (! is_array($result['Results']) || ! array_is_list($result['Results'])))) {
            throw new ClsLogException(trans('admin::cls.unavailable'));
        }
        foreach ($result['Results'] ?? [] as $log) {
            if (! is_array($log)) {
                throw new ClsLogException(trans('admin::cls.unavailable'));
            }
        }

        return $result;
    }

    protected function request(string $action, array $parameters): array
    {
        foreach (['region', 'secret_id', 'secret_key'] as $key) {
            if (! is_string(config("admin.cls.{$key}")) || trim(config("admin.cls.{$key}")) === '') {
                throw new ClsLogException(trans('admin::cls.not_configured'));
            }
        }

        $body = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();
        $headers = [
            'Host' => self::HOST,
            'Authorization' => $this->authorization($body, $timestamp),
            'X-TC-Action' => $action,
            'X-TC-Version' => '2020-10-16',
            'X-TC-Region' => config('admin.cls.region'),
            'X-TC-Timestamp' => (string) $timestamp,
        ];
        if ($token = config('admin.cls.token')) {
            $headers['X-TC-Token'] = $token;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($headers)->withBody($body, 'application/json')
                ->post('https://'.self::HOST);
        } catch (ConnectionException $exception) {
            // HTTP exceptions can contain signed headers. Do not log the exception.
            throw new ClsLogException(trans('admin::cls.unavailable'));
        }

        $result = $response->json('Response');
        if (! $response->successful() || ! is_array($result) || isset($result['Error'])) {
            Log::warning('CLS log query failed', [
                'action' => $action,
                'status' => $response->status(),
                'code' => is_array($result) ? ($result['Error']['Code'] ?? null) : null,
                'request_id' => is_array($result) ? ($result['RequestId'] ?? null) : null,
            ]);
            throw new ClsLogException(trans('admin::cls.unavailable'));
        }
        return $result;
    }

    /**
     * Tencent Cloud API 3.0, TC3-HMAC-SHA256. Sign exactly the bytes sent by HTTP.
     */
    protected function authorization(string $body, int $timestamp): string
    {
        $date = gmdate('Y-m-d', $timestamp);
        $scope = $date.'/cls/tc3_request';
        $canonical = "POST\n/\n\ncontent-type:application/json\nhost:".self::HOST
            ."\n\ncontent-type;host\n".hash('sha256', $body);
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n".hash('sha256', $canonical);
        $dateKey = hash_hmac('sha256', $date, 'TC3'.config('admin.cls.secret_key'), true);
        $serviceKey = hash_hmac('sha256', 'cls', $dateKey, true);
        $signingKey = hash_hmac('sha256', 'tc3_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return 'TC3-HMAC-SHA256 Credential='.config('admin.cls.secret_id').'/'.$scope
            .', SignedHeaders=content-type;host, Signature='.$signature;
    }
}
