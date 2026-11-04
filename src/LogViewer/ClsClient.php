<?php

namespace Dcat\Admin\LogViewer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClsClient
{
    private const HOST = 'cls.tencentcloudapi.com';

    public function search(array $parameters): array
    {
        foreach (['region', 'topic_id', 'secret_id', 'secret_key'] as $key) {
            if (! is_string(config("admin.cls.{$key}")) || trim(config("admin.cls.{$key}")) === '') {
                throw new ClsLogException(trans('admin::cls.not_configured'));
            }
        }

        // Keep the topic under server control, including when this client is reused.
        $parameters['TopicId'] = config('admin.cls.topic_id');
        $body = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();
        $headers = [
            'Host' => self::HOST,
            'Authorization' => $this->authorization($body, $timestamp),
            'X-TC-Action' => 'SearchLog',
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
            Log::warning('CLS log search failed', [
                'status' => $response->status(),
                'code' => is_array($result) ? ($result['Error']['Code'] ?? null) : null,
                'request_id' => is_array($result) ? ($result['RequestId'] ?? null) : null,
            ]);
            throw new ClsLogException(trans('admin::cls.unavailable'));
        }
        if (! array_key_exists('Results', $result) || ! is_bool($result['ListOver'] ?? null)
            || ($result['Results'] !== null && ! is_array($result['Results']))) {
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
