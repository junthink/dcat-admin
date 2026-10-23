<?php

namespace Dcat\Admin\Http\Actions\Extensions;

use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Dcat\Admin\Traits\RequiresAdministrator;

class Uninstall extends RowAction
{
    use RequiresAdministrator;

    public function title()
    {
        $label = trans('admin.uninstall');

        return "<span class='text-danger'>{$label}</span>";
    }

    public function confirm()
    {
        return [trans('admin.confirm_uninstall'), $this->getKey()];
    }

    public function handle()
    {
        $manager = Admin::extension()
            ->updateManager()
            ->rollback($this->getKey());

        Admin::extension()->get($this->getKey())->uninstall();

        return $this
            ->response()
            ->success(implode('<br>', $manager->notes))
            ->refresh();
    }
}
