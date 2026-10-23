<?php

namespace Dcat\Admin\Traits;

trait RequiresAdministrator
{
    protected function authorize($user): bool
    {
        return $user && $user->isAdministrator();
    }

    public function failedAuthorization()
    {
        abort(403, __('admin.deny'));
    }
}
