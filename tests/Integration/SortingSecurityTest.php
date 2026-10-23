<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Grid;
use Dcat\Admin\Models\Administrator;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SortingSecurityTest extends TestCase
{
    public static function invalidSorts(): array
    {
        return [
            [['column' => 'name', 'type' => 'asc, (SELECT 1)']],
            [['column' => "data.x')) --", 'type' => 'asc']],
            [['column' => 'name', 'type' => 'asc', 'cast' => 'SIGNED) DESC, (SELECT 1) --']],
            [['column' => ['name'], 'type' => 'asc']],
            [['column' => 'save.unexpected', 'type' => 'asc']],
            ['invalid'],
        ];
    }

    #[DataProvider('invalidSorts')]
    public function test_untrusted_sort_expressions_are_rejected($sort): void
    {
        $this->app->instance('request', Request::create('/', 'GET', ['_sort' => $sort]));
        $grid = new Grid(new Administrator());
        $this->expectException(HttpException::class);
        $grid->model()->getSort();
    }

    public function test_normal_and_json_sort_parameters_remain_supported(): void
    {
        foreach ([['name', 'desc', null], ['profile.age', 'asc', 'SIGNED'], ['profile->age', 'asc', 'DECIMAL(10,2)']] as [$column, $type, $cast]) {
            $this->app->instance('request', Request::create('/', 'GET', ['_sort' => compact('column', 'type', 'cast')]));
            $grid = new Grid(new Administrator());
            $grid->column($column)->sortable(null, $cast);
            $this->assertSame([$column, $type, $cast], $grid->model()->getSort());
        }
    }
}
