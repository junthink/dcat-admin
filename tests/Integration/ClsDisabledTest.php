<?php

namespace Dcat\Admin\Tests;

use Illuminate\Support\Facades\Http;

class ClsDisabledTest extends TestCase
{
    public function test_cls_does_not_register_a_route_by_default(): void
    {
        Http::fake();
        $this->assertFalse(config('admin.cls.enabled'));
        $this->get('/admin/cls-logs')->assertNotFound();
        Http::assertNothingSent();
    }
}
