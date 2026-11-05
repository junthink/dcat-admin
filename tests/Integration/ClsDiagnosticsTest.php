<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\LogViewer\ClsClient;
use Dcat\Admin\LogViewer\ClsLogException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClsDiagnosticsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('admin.cls', [
            'region' => 'me-saudi-arabia', 'topic_id' => 'topic-test',
            'secret_id' => 'test-id', 'secret_key' => 'test-secret', 'token' => 'test-token',
        ]);
    }

    private function failure(): string
    {
        try {
            app(ClsClient::class)->search(['QueryString' => 'private keyword']);
            $this->fail('Expected query failure');
        } catch (ClsLogException $exception) {
            $this->assertStringContainsString('Diagnostic ID: ', $exception->getMessage());

            return $exception->getMessage();
        }
    }

    public function test_cloud_reason_and_request_id_are_logged_with_redaction_and_page_correlation(): void
    {
        Log::spy();
        Http::fake(['cls.tencentcloudapi.com' => Http::response(['Response' => [
            'Error' => ['Code' => 'UnauthorizedOperation', 'Message' => 'SearchLog denied: test-id test-secret test-token Signature=abcdef'],
            'RequestId' => 'request-test',
        ]])]);
        $message = $this->failure();
        $this->assertStringNotContainsString('SearchLog denied', $message);
        Log::shouldHaveReceived('warning')->once()->with('CLS log query failed', \Mockery::on(function ($context) use ($message) {
            $this->assertSame('cloud_api_error', $context['reason']);
            $this->assertSame('SearchLog', $context['action']);
            $this->assertSame('me-saudi-arabia', $context['region']);
            $this->assertSame('topic-test', $context['topic_id']);
            $this->assertSame('UnauthorizedOperation', $context['code']);
            $this->assertSame('request-test', $context['request_id']);
            $this->assertStringContainsString('SearchLog denied', $context['cloud_message']);
            $this->assertStringContainsString($context['diagnostic_id'], $message);
            foreach (['test-id', 'test-secret', 'test-token', 'abcdef', 'private keyword'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($context));
            }

            return true;
        }));
    }

    public function test_connection_errors_log_safe_curl_cause_without_exception_headers(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out; Authorization: TC3-HMAC-SHA256 Credential=test-id; private-header-value'));
        $this->failure();
        Log::shouldHaveReceived('warning')->once()->with('CLS log query failed', \Mockery::on(function ($context) {
            $this->assertSame('connection_failed', $context['reason']);
            $this->assertSame('timeout', $context['cause']);
            $this->assertSame(28, $context['curl_errno']);
            $this->assertStringNotContainsString('private-header-value', json_encode($context));

            return true;
        }));
    }

    public function test_missing_configuration_logs_field_name_without_making_a_request(): void
    {
        Log::spy();
        config(['admin.cls.secret_key' => '']);
        Http::fake();
        $this->failure();
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with('CLS log query failed', \Mockery::on(fn ($c) => $c['reason'] === 'missing_configuration' && $c['missing_field'] === 'secret_key'));
    }

    public function test_malformed_success_responses_log_shape_failure_without_raw_response(): void
    {
        Log::spy();
        Http::fake(['cls.tencentcloudapi.com' => Http::response(['Response' => ['Results' => 'private raw logs', 'ListOver' => true, 'RequestId' => 'request-shape']])]);
        $this->failure();
        Log::shouldHaveReceived('warning')->once()->with('CLS log query failed', \Mockery::on(function ($context) {
            $this->assertSame('invalid_search_response', $context['reason']);
            $this->assertSame('request-shape', $context['request_id']);
            $this->assertStringNotContainsString('private raw logs', json_encode($context));

            return true;
        }));
    }
}
