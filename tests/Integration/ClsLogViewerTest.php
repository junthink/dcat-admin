<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\LogViewer\ClsLogMenu;
use Dcat\Admin\LogViewer\ClsLogViewer;
use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Models\Role;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class ClsLogViewerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('admin.cls', [
            'enabled' => true, 'region' => 'ap-guangzhou', 'topic_id' => 'topic-test',
            'secret_id' => 'test-id', 'secret_key' => 'test-secret', 'token' => 'test-token',
            'services' => ['api' => 'API', 'worker' => 'Worker'],
            'service_field' => '__TAG__.container_name', 'level_field' => 'level_name',
            'message_field' => 'message', 'timezone' => 'Asia/Shanghai', 'page_size' => 2,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function login(bool $grant = true): Administrator
    {
        $user = Administrator::create(['username' => 'cls-reader', 'name' => 'Reader', 'password' => 'unused']);
        $role = Role::create(['name' => 'Reader', 'slug' => 'cls-reader']);
        if ($grant) {
            $permission = app(ClsLogMenu::class)->install();
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
        $this->actingAs($user, 'admin');

        return $user;
    }

    private function filters(array $overrides = []): array
    {
        return array_replace([
            'start' => '2026-11-04T09:00:00', 'end' => '2026-11-04T10:00:00',
            'service' => 'api', 'level' => 'ERROR', 'keyword' => '支付失败',
        ], $overrides);
    }

    private function fakeLogs(bool $over = true): void
    {
        Http::fake(['cls.tencentcloudapi.com' => Http::response(['Response' => [
            'ListOver' => $over, 'Results' => [[
                'Time' => 1793755800123, 'HostName' => 'api-pod',
                'LogJson' => json_encode(['__TAG__.container_name' => 'api', 'level_name' => 'ERROR', 'message' => '<script>alert(1)</script>']),
            ]],
        ]])]);
    }

    public function test_log_search_requires_login_and_dcat_permissions_before_calling_cls(): void
    {
        Http::fake();
        $this->get('/admin/cls-logs')->assertRedirect('/admin/auth/login');
        $this->login(false);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get('/admin/cls-logs')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_disabled_feature_is_not_accessible_even_with_cached_routes(): void
    {
        $this->login();
        config(['admin.cls.enabled' => false]);
        Http::fake();
        $this->get('/admin/cls-logs')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_authorized_page_escapes_log_html_and_sends_current_api_parameters(): void
    {
        $this->login();
        $this->travelTo(Carbon::parse('2026-11-04T02:00:00Z'));
        $this->fakeLogs();
        $this->get('/admin/cls-logs?'.http_build_query($this->filters(['TopicId' => 'untrusted-topic'])))
            ->assertOk()->assertSee('CLS business logs')->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(function ($request) {
            return $request->url() === 'https://cls.tencentcloudapi.com'
                && $request['TopicId'] === 'topic-test'
                && $request['From'] === 1793754000000 && $request['To'] === 1793757600000
                && $request['QueryString'] === '__TAG__.container_name:"api" AND level_name:"ERROR" AND "支付失败"'
                && $request['QuerySyntax'] === 1 && $request['Offset'] === 0 && $request['Limit'] === 2
                && $request->hasHeader('X-TC-Action', 'SearchLog')
                && $request->hasHeader('X-TC-Version', '2020-10-16')
                && $request->hasHeader('X-TC-Token', 'test-token')
                // Golden value independently calculated with Python hmac/hashlib from the transmitted UTF-8 body.
                && $request->hasHeader('Authorization', 'TC3-HMAC-SHA256 Credential=test-id/2026-11-04/cls/tc3_request, SignedHeaders=content-type;host, Signature=2f245be3cb6f00ebcb2db0b9f49eeece754e02923ceede71af88b21d07412800')
                && ! isset($request['Query']) && ! isset($request['SyntaxRule']);
        });
    }

    public function test_default_search_and_empty_optional_filters_are_valid(): void
    {
        $this->login();
        Http::fake(['cls.tencentcloudapi.com' => Http::response(['Response' => ['ListOver' => true, 'Results' => null]])]);
        $this->get('/admin/cls-logs')->assertOk()->assertSee('No data.');
        $this->get('/admin/cls-logs?service=&level=&keyword=')->assertOk();
        Http::assertSent(fn ($request) => $request['QueryString'] === '*' && $request['To'] - $request['From'] === 3600000);
    }

    public function test_pagination_preserves_fixed_time_range_and_filters(): void
    {
        $this->login();
        $this->fakeLogs(false);
        $response = $this->get('/admin/cls-logs?'.http_build_query($this->filters()));
        $response->assertOk();
        $document = new DOMDocument();
        @$document->loadHTML($response->getContent());
        $next = (new DOMXPath($document))->query('//a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame($this->filters(), array_intersect_key($query, $this->filters()));
        $this->assertSame('2', $query['page']);
        $this->get($next)->assertOk()->assertSee('rel="prev"', false);
        Http::assertSent(fn ($request) => $request['Offset'] === 2 && $request['From'] === 1793754000000 && $request['To'] === 1793757600000);
    }

    public static function invalidFilters(): array
    {
        return [
            [['start' => 'invalid']], [['start' => '2026-11-04T11:00:00']],
            [['start' => '2026-10-01T09:00:00']], [['service' => 'unconfigured']],
            [['level' => 'ALL OR *']], [['keyword' => ['array']]],
            [['keyword' => "line\nfeed"]], [['page' => -1]], [['page' => '1e9']],
            [['start' => ['array']]], [['end' => ['array']]], [['service' => ['api']]], [['cursor' => ['array']]],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_never_reach_cls(array $invalid): void
    {
        $this->login();
        Http::fake();
        $this->get('/admin/cls-logs?'.http_build_query($this->filters($invalid)))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_keyword_is_quoted_and_cannot_replace_service_or_level_conditions(): void
    {
        $keyword = 'a" OR * | SELECT * \\';
        $viewer = app(ClsLogViewer::class);
        $this->fakeLogs();
        $viewer->search($viewer->filters($this->filters(['keyword' => $keyword])));
        Http::assertSent(fn ($request) => $request['QueryString'] === '__TAG__.container_name:"api" AND level_name:"ERROR" AND "a\\" OR * | SELECT * \\\\"');
    }

    public function test_missing_configuration_does_not_make_a_cloud_request(): void
    {
        $this->login();
        config(['admin.cls.secret_key' => '']);
        Http::fake();
        $this->get('/admin/cls-logs')->assertStatus(503)->assertSee('has not been configured');
        Http::assertNothingSent();
    }

    public function test_cls_errors_are_visible_as_errors_without_exposing_cloud_details(): void
    {
        $this->login();
        Http::fake(['cls.tencentcloudapi.com' => Http::response(['Response' => [
            'Error' => ['Code' => 'AuthFailure', 'Message' => 'test-secret must not be shown'], 'RequestId' => 'request-test',
        ]])]);
        $this->get('/admin/cls-logs')->assertStatus(503)->assertSee('temporarily unavailable')
            ->assertDontSee('test-secret')->assertDontSee('No data.');
    }

    public function test_connection_failure_does_not_look_like_empty_logs(): void
    {
        $this->login();
        Http::fake(['cls.tencentcloudapi.com' => Http::failedConnection()]);
        $this->get('/admin/cls-logs')->assertStatus(503)->assertDontSee('No data.');
    }

    public static function malformedResponses(): array
    {
        return [
            [['Response' => []]], ['invalid JSON'],
            [['Response' => ['Results' => [], 'ListOver' => 'true']]],
            [['Response' => ['Results' => 'invalid', 'ListOver' => true]]],
        ];
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_response_does_not_look_like_empty_logs(array|string $body): void
    {
        $this->login();
        Http::fake(['cls.tencentcloudapi.com' => Http::response($body)]);
        $this->get('/admin/cls-logs')->assertStatus(503)->assertDontSee('No data.');
    }

    public function test_custom_fields_numeric_level_and_nested_json_are_supported(): void
    {
        config(['admin.cls.service_field' => 'app.name', 'admin.cls.level_field' => 'level', 'admin.cls.level_values' => ['ERROR' => 400]]);
        Http::fake(['cls.tencentcloudapi.com' => Http::response(['Response' => ['ListOver' => true, 'Results' => [[
            'Time' => 1793755800123, 'LogJson' => '{"app":{"name":"api"},"level":400,"message":"Failure"}',
        ]]]])]);
        $viewer = app(ClsLogViewer::class);
        $result = $viewer->search($viewer->filters($this->filters()));
        $this->assertSame('api', $result['logs'][0]['service']);
        $this->assertSame('ERROR', $result['logs'][0]['level']);
        $this->assertSame('2026-11-04 09:30:00.123', $result['logs'][0]['time']);
        Http::assertSent(fn ($request) => str_contains($request['QueryString'], 'app.name:"api" AND level:"400"'));
    }

    public function test_menu_installation_is_idempotent_and_does_not_grant_roles(): void
    {
        $this->artisan('admin:cls-install')->assertSuccessful();
        $this->artisan('admin:cls-install')->assertSuccessful();
        $this->assertDatabaseCount('admin_menu', 1);
        $this->assertDatabaseCount('admin_permissions', 1);
        $this->assertDatabaseCount('admin_permission_menu', 1);
        $this->assertDatabaseCount('admin_role_permissions', 0);
        $this->assertDatabaseHas('admin_permissions', ['slug' => 'cls-logs', 'http_method' => 'GET', 'http_path' => '/cls-logs']);
    }
}
