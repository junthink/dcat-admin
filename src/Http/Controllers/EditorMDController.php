<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Support\EditorUpload;
use Illuminate\Http\Request;

class EditorMDController
{
    public function upload(Request $request)
    {
        return ['success' => 1, 'url' => EditorUpload::store($request, 'editormd-image-file')];
    }
}
