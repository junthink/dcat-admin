<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Models\Menu;
use Dcat\Admin\Models\Role;
use Dcat\Admin\Show;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class LaravelCompatibilityTest extends TestCase
{
    public function test_package_boots_with_laravel_13_and_migrates_tables(): void
    {
        $this->assertStringStartsWith('13.', $this->app->version());
        $this->assertTrue($this->app['db']->connection()->getSchemaBuilder()->hasTable('admin_users'));
        $this->assertTrue($this->app['db']->connection()->getSchemaBuilder()->hasTable('admin_extensions'));
        $this->assertNotEmpty(Admin::app()->getName());
    }

    public function test_login_page_renders_and_guest_api_access_is_denied(): void
    {
        $this->get('/admin/auth/login')->assertOk()->assertSee('Dcat Admin');
        $this->post('/admin/dcat-api/action', ['_action' => 'invalid'])->assertRedirect('/admin/auth/login');
    }

    public function test_authentication_and_admin_builders_work(): void
    {
        $user = Administrator::create([
            'username' => 'integration-admin', 'name' => 'Integration Admin',
            'password' => Hash::make('a-long-test-password'),
        ]);
        $role = Role::create(['name' => 'Administrator', 'slug' => Role::ADMINISTRATOR]);
        $user->roles()->attach($role);

        $this->post('/admin/auth/login', [
            'username' => 'integration-admin', 'password' => 'a-long-test-password',
        ])->assertOk();
        $this->assertAuthenticatedAs($user, 'admin');

        $this->get('/admin/auth/users')->assertOk()->assertSee('integration-admin');
        $this->get('/admin/auth/users?'.http_build_query(['_sort' => ['column' => 'id', 'type' => 'desc']]))->assertOk();
        $this->get('/admin/auth/users/'.$user->id.'/edit')->assertOk();
        $this->assertNotEmpty((new Form(new Administrator()))->text('name'));
        $this->assertInstanceOf(Grid::class, new Grid(new Administrator()));
        $this->assertInstanceOf(Show::class, new Show($user->id, new Administrator()));
    }

    public function test_tree_sorting_works_with_updated_spatie_dependency(): void
    {
        $first = Menu::create(['title' => 'First', 'parent_id' => 0, 'order' => 1, 'uri' => '/']);
        $second = Menu::create(['title' => 'Second', 'parent_id' => 0, 'order' => 2, 'uri' => '/second']);
        Menu::saveOrder([['id' => $second->id], ['id' => $first->id]]);
        $this->assertSame([$second->id, $first->id], Menu::orderBy('order')->pluck('id')->all());
    }

    public function test_menu_cache_works_with_object_deserialization_disabled(): void
    {
        $path = sys_get_temp_dir().'/dcat-menu-cache-'.bin2hex(random_bytes(8));
        config([
            'cache.serializable_classes' => false,
            'cache.stores.secure_menus' => ['driver' => 'file', 'path' => $path],
            'admin.menu.cache.enable' => true,
            'admin.menu.cache.store' => 'secure_menus',
        ]);

        try {
            $menu = Menu::create(['title' => 'Cached', 'parent_id' => 0, 'order' => 1, 'uri' => '/']);
            $role = Role::create(['name' => 'Cached Role', 'slug' => 'cached-role']);
            $menu->roles()->attach($role);
            $first = (new Menu())->allNodes();
            $second = (new Menu())->allNodes();
            $this->assertSame($first->toArray(), $second->toArray());
            $this->assertInstanceOf(Menu::class, $second->first());
            $this->assertInstanceOf(Role::class, $second->first()->roles->first());
            $this->assertSame($menu->id, $second->first()->roles->first()->pivot->menu_id);
            $cached = Cache::store('secure_menus')->get('dcat-admin-menus-v2-'.(int) Menu::withPermission().'-admin');
            $this->assertIsArray($cached);
        } finally {
            Cache::store('secure_menus')->flush();
            $this->app['files']->deleteDirectory($path);
        }
    }
}
