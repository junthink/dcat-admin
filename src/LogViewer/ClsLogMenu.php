<?php

namespace Dcat\Admin\LogViewer;

class ClsLogMenu
{
    public function install()
    {
        $menuClass = config('admin.database.menu_model');
        $permissionClass = config('admin.database.permissions_model');
        $connection = (new $menuClass())->getConnection();

        return $connection->transaction(function () use ($menuClass, $permissionClass) {
            $permission = $permissionClass::firstOrNew(['slug' => 'cls-logs']);
            $permission->fill([
                'name' => trans('admin::cls.title'), 'http_method' => ['GET'], 'http_path' => ['/cls-logs'],
            ])->save();
            $menu = $menuClass::firstOrNew(['uri' => 'cls-logs']);
            if (! $menu->exists) {
                $menu->parent_id = 0;
                $menu->order = (int) $menuClass::max('order') + 1;
            }
            $menu->fill([
                'title' => trans('admin::cls.title'), 'icon' => 'feather icon-file-text',
                'extension' => '', 'show' => 1,
            ])->save();
            $menu->permissions()->syncWithoutDetaching([$permission->getKey()]);

            return $permission;
        });
    }
}
