<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Support\EditorUpload;
use Illuminate\Http\Request;

class TinymceController
{
    public function upload(Request $request)
    {
        return ['location' => EditorUpload::store($request, 'file')];
    }
}
