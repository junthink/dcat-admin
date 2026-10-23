<?php

namespace Dcat\Admin\Http\Controllers;

use Exception;
use Dcat\Admin\Traits\InteractsWithApi;
use Dcat\Admin\Support\Helper;
use Illuminate\Http\Request;

class ValueController
{
    /**
     * @param  Request  $request
     * @return mixed
     */
    public function handle(Request $request)
    {
        $instance = $this->resolve($request);

        if (! $instance->passesAuthorization()) {
            return $instance->failedAuthorization();
        }

        $response = $instance->handle($request);

        if ($response) {
            return $response;
        }

        if (method_exists($instance, 'valueResult')) {
            return $instance->valueResult();
        }
    }

    /**
     * @param  Request  $request
     * @return \Dcat\Admin\Traits\InteractsWithApi
     *
     * @throws Exception
     */
    protected function resolve(Request $request)
    {
        $key = $request->get('_key');
        abort_unless(
            Helper::validateClassName($key) && class_exists($key)
            && in_array(InteractsWithApi::class, class_uses_recursive($key), true),
            400,
            'Invalid value handler.'
        );

        $instance = app($key);

        if (! method_exists($instance, 'handle')) {
            throw new Exception("The method '{$key}::handle()' does not exist.");
        }

        return $instance;
    }
}
