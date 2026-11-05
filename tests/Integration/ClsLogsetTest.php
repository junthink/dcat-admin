<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\LogViewer\ClsClient;
use Dcat\Admin\LogViewer\ClsLogException;
use Dcat\Admin\LogViewer\ClsLogMenu;
use Dcat\Admin\LogViewer\ClsLogViewer;
use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Models\Role;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ClsLogsetTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('admin.cls', [
            'enabled' => true, 'region' => 'me-saudi-arabia', 'logset_name' => 'imee-prod',
            'secret_id' => 'test-id', 'secret_key' => 'test-secret', 'page_size' => 2,
            'level_field' => 'level_name', 'message_field' => 'message', 'timezone' => 'Asia/Shanghai',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function login(string $name = 'reader', bool $grant = true): void
    {
        $user = Administrator::create(['username' => $name, 'name' => $name, 'password' => 'unused']);
        $role = Role::create(['name' => $name, 'slug' => $name]);
        if ($grant) {
            $role->permissions()->attach(app(ClsLogMenu::class)->install());
        }
        $user->roles()->attach($role);
        $this->actingAs($user, 'admin');
    }

    private function filters(array $input = []): array
    {
        return array_replace([
            'start' => '2026-11-04T09:00:00', 'end' => '2026-11-04T10:00:00',
            'service' => '', 'level' => '', 'keyword' => '', 'page' => 1, 'cursor' => '',
        ], $input);
    }

    private function topic(string $id, string $name): array
    {
        return ['TopicId' => $id, 'TopicName' => $name, 'LogsetId' => 'set-prod'];
    }

    private function fake(?\Closure $override = null): void
    {
        Http::fake(function ($request) use ($override) {
            $action = $request->header('X-TC-Action')[0];
            $result = $override ? $override($action, $request) : null;
            if ($result === null) {
                $result = match ($action) {
                    'DescribeLogsets' => ['Logsets' => [['LogsetId' => 'set-prod', 'LogsetName' => 'imee-prod']], 'TotalCount' => 1],
                    'DescribeTopics' => ['Topics' => [$this->topic('api', 'live-server-prod-stdout'), $this->topic('worker', 'mqtt-server-prod-stdout')], 'TotalCount' => 2],
                    'SearchLog' => $this->logs($request['TopicId'], $request['Offset'], $request['Limit']),
                };
            }

            return Http::response(['Response' => $result]);
        });
    }

    private function logs(string $id, int $offset, int $limit): array
    {
        // Equal timestamps exercise deterministic ordering across page boundaries.
        $times = $id === 'api' ? [900, 700, 500, 100] : [800, 700, 600, 200];
        $rows = [];
        foreach (array_slice($times, $offset, $limit) as $time) {
            $rows[] = ['Time' => 1793754000000 + $time, 'TopicId' => $id,
                'LogJson' => json_encode(['level_name' => 'ERROR', 'message' => $id.'-'.$time])];
        }

        return ['Results' => $rows, 'ListOver' => $offset + count($rows) >= count($times)];
    }

    public function test_discovery_is_paginated_scoped_and_cached_only_for_the_request(): void
    {
        $this->fake(function ($action, $request) {
            if ($action === 'DescribeTopics') {
                $this->assertSame([['Key' => 'logsetId', 'Values' => ['set-prod']]], $request['Filters']);
                $this->assertSame(100, $request['Limit']);

                return ['Topics' => [$this->topic($request['Offset'] === 0 ? 'api' : 'worker', $request['Offset'] === 0 ? 'API' : 'Worker')], 'TotalCount' => 2];
            }
            if ($action === 'DescribeLogsets') {
                $this->assertSame([['Key' => 'logsetName', 'Values' => ['imee-prod']]], $request['Filters']);
            }

            return null;
        });
        $viewer = app(ClsLogViewer::class);
        $this->assertSame(['api' => 'API', 'worker' => 'Worker'], $viewer->services());
        $this->assertSame($viewer->services(), $viewer->services());
        Http::assertSentCount(3);
    }

    public function test_logset_id_takes_priority_and_single_application_uses_topic_not_service_field(): void
    {
        config(['admin.cls.logset_id' => 'set-prod', 'admin.cls.topic_id' => 'ignored-old-topic']);
        $this->fake();
        $viewer = app(ClsLogViewer::class);
        $result = $viewer->search($viewer->filters($this->filters(['service' => 'api', 'level' => 'ERROR', 'keyword' => 'geo'])));
        $this->assertSame('live-server-prod-stdout', $result['logs'][0]['service']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->header('X-TC-Action')[0] === 'SearchLog'
            && $r['TopicId'] === 'api' && $r['QueryString'] === 'level_name:"ERROR" AND "geo"'
            && ! isset($r['Topics']) && ! isset($r['Context']));
    }

    public function test_all_applications_merge_without_loss_or_duplicates_and_previous_page_is_repeatable(): void
    {
        $this->fake();
        $input = $this->filters();
        $messages = [];
        $inputs = [];
        for ($page = 1; $page <= 4; $page++) {
            $inputs[] = $input;
            $viewer = app(ClsLogViewer::class);
            $result = $viewer->search($viewer->filters($input));
            $messages = array_merge($messages, array_column($result['logs'], 'message'));
            $this->assertSame($page < 4, $result['has_more']);
            $input = array_replace($input, ['page' => $page + 1, 'cursor' => $result['next_cursor']]);
        }
        $this->assertSame(['api-900', 'worker-800', 'api-700', 'worker-700', 'worker-600', 'api-500', 'worker-200', 'api-100'], $messages);
        $this->assertSame($inputs[2]['cursor'], $result['previous_cursor']);
        $again = app(ClsLogViewer::class)->search($inputs[2]);
        $this->assertSame(['worker-600', 'api-500'], array_column($again['logs'], 'message'));
    }

    public function test_authorized_page_lists_topics_and_preserves_filters_in_cursor_links(): void
    {
        $this->login();
        $this->fake();
        $response = $this->get('/admin/cls-logs?'.http_build_query($this->filters(['level' => 'ERROR'])));
        $response->assertOk()->assertSee('All applications')->assertSee('live-server-prod-stdout')->assertSee('mqtt-server-prod-stdout');
        $document = new DOMDocument();
        @$document->loadHTML($response->getContent());
        $next = (new DOMXPath($document))->query('//a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('2', $query['page']);
        $this->assertSame('ERROR', $query['level']);
        $this->assertSame(64, strlen($query['cursor']));
        $this->get($next)->assertOk()->assertSee('rel="prev"', false)->assertSee('api-700')->assertSee('worker-700');
    }

    public function test_topic_discovery_never_runs_before_login_and_permission_checks(): void
    {
        Http::fake();
        $this->get('/admin/cls-logs')->assertRedirect('/admin/auth/login');
        $this->login('unprivileged', false);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get('/admin/cls-logs')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_out_of_logset_application_is_rejected_and_client_cannot_bypass_scope(): void
    {
        $this->fake();
        try {
            app(ClsLogViewer::class)->filters($this->filters(['service' => 'foreign-topic']));
            $this->fail('Expected invalid application');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('service', $exception->errors());
        }
        $this->expectException(ClsLogException::class);
        app(ClsClient::class)->searchTopic(['TopicId' => 'api'], 'foreign-topic');
    }

    public function test_expired_or_filter_changed_cursor_fails_without_search(): void
    {
        $this->fake();
        $viewer = app(ClsLogViewer::class);
        $result = $viewer->search($this->filters());
        $input = $this->filters(['page' => 2, 'cursor' => $result['next_cursor'], 'keyword' => 'changed']);
        $this->expectExceptionMessage('Please search again');
        $viewer->search($input);
    }

    public function test_cursor_is_bound_to_reader_and_expires(): void
    {
        $this->login();
        $this->fake();
        $result = app(ClsLogViewer::class)->search($this->filters());
        $this->login('second-reader');
        $input = $this->filters(['page' => 2, 'cursor' => $result['next_cursor']]);
        try {
            app(ClsLogViewer::class)->search($input);
            $this->fail('Expected reader-bound cursor');
        } catch (ClsLogException $exception) {
            $this->assertStringContainsString('Please search again', $exception->getMessage());
        }
        $result = app(ClsLogViewer::class)->search($this->filters());
        $input['cursor'] = $result['next_cursor'];
        $this->travel(11)->minutes();
        $this->expectExceptionMessage('Please search again');
        app(ClsLogViewer::class)->search($input);
    }

    public function test_cache_failure_does_not_leak_an_exception_or_show_partial_logs(): void
    {
        $this->login();
        $this->fake();
        Log::spy();
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('private cache connection'));
        $this->get('/admin/cls-logs')->assertStatus(503)->assertSee('Diagnostic ID:')->assertDontSee('private cache connection')->assertDontSee('api-900');
        Log::shouldHaveReceived('warning')->once()->with('CLS log query failed', \Mockery::on(fn ($c) => $c['reason'] === 'pagination_cache_write_failed' && $c['cache_store'] === 'array'));
    }

    public function test_empty_logset_is_empty_without_a_search_request(): void
    {
        $this->fake(fn ($action) => $action === 'DescribeTopics' ? ['Topics' => [], 'TotalCount' => 0] : null);
        $result = app(ClsLogViewer::class)->search($this->filters());
        $this->assertSame([], $result['logs']);
        $this->assertFalse($result['has_more']);
        Http::assertSentCount(2);
    }

    public function test_discovery_failure_is_a_sanitized_page_error_not_an_empty_result(): void
    {
        $this->login();
        $this->fake(fn () => ['Error' => ['Code' => 'UnauthorizedOperation', 'Message' => 'test-secret']]);
        $this->get('/admin/cls-logs')->assertStatus(503)->assertSee('temporarily unavailable')->assertDontSee('test-secret')->assertDontSee('No data.');
        Http::assertSentCount(1);
    }

    public function test_similarly_named_logsets_are_not_accepted(): void
    {
        $this->fake(fn () => ['Logsets' => [['LogsetId' => 'set-test', 'LogsetName' => 'imee-prod-test']], 'TotalCount' => 1]);
        $this->expectExceptionMessage('uniquely identified');
        app(ClsLogViewer::class)->services();
    }

    public function test_one_failed_topic_does_not_appear_as_successful_partial_logs(): void
    {
        $this->login();
        $this->fake(fn ($action, $r) => $action === 'SearchLog' && $r['TopicId'] === 'worker'
            ? ['Error' => ['Code' => 'UnauthorizedOperation', 'Message' => 'private']] : null);
        $this->get('/admin/cls-logs')->assertStatus(503)->assertDontSee('api-900')->assertDontSee('No data.');
    }

    public function test_all_applications_include_more_than_fifty_topics(): void
    {
        config(['admin.cls.page_size' => 100]);
        $this->fake(function ($action, $r) {
            if ($action === 'DescribeTopics') {
                $topics = [];
                for ($id = 1; $id <= 55; $id++) {
                    $topics[] = $this->topic('topic-'.$id, 'App '.$id);
                }

                return ['Topics' => $topics, 'TotalCount' => 55];
            }
            if ($action === 'SearchLog') {
                return ['Results' => [['TopicId' => $r['TopicId'], 'Time' => 1793754000000, 'LogJson' => '{"message":"same"}']], 'ListOver' => true];
            }

            return null;
        });
        $result = app(ClsLogViewer::class)->search($this->filters());
        $this->assertCount(55, $result['logs']);
        $this->assertCount(55, array_unique(array_column($result['logs'], 'service')));
        $this->assertFalse($result['has_more']);
        Http::assertSentCount(57);
    }

    public function test_discovery_cannot_return_a_topic_from_another_logset(): void
    {
        $this->fake(fn ($action) => $action === 'DescribeTopics' ? ['Topics' => [
            ['TopicId' => 'foreign', 'TopicName' => 'Foreign', 'LogsetId' => 'other-set'],
        ], 'TotalCount' => 1] : null);
        $this->expectException(ClsLogException::class);
        app(ClsLogViewer::class)->services();
    }

    public function test_inconsistent_discovery_pages_are_an_error_not_a_truncated_catalog(): void
    {
        $this->fake(fn ($action) => $action === 'DescribeTopics' ? ['Topics' => [], 'TotalCount' => 1] : null);
        $this->expectException(ClsLogException::class);
        app(ClsLogViewer::class)->services();
    }
}
