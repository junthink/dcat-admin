<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Actions\Action;
use Dcat\Admin\Actions\Response;
use Dcat\Admin\Exception\AdminException;
use Dcat\Admin\Support\Helper;
use Illuminate\Http\Request;

class HandleActionController
{
    /**
     * @param  Request  $request
     * @return $this|\Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        $action = $this->resolveActionInstance($request);

        $action->setKey($request->get('_key'));

        if (! $action->passesAuthorization()) {
            $response = $action->failedAuthorization();
        } else {
            $response = $action->handle($request);
        }

        return $response instanceof Response ? $response->send() : $response;
    }

    /**
     * @param  Request  $request
     * @return Action
     *
     * @throws AdminException
     */
    protected function resolveActionInstance(Request $request): Action
    {
        $name = $request->get('_action');
        abort_unless(is_string($name), 400, 'Invalid action request.');
        $actionClass = str_replace('_', '\\', $name);
        abort_unless(Helper::validateClassName($actionClass) && is_subclass_of($actionClass, Action::class), 400, 'Invalid action class.');

        /** @var Action $action */
        $action = app($actionClass);

        if (! method_exists($action, 'handle')) {
            throw new AdminException("Action method {$actionClass}::handle() does not exist.");
        }

        return $action;
    }
}
