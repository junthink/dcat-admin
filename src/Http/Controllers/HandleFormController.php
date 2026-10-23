<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Exception\AdminException;
use Dcat\Admin\Contracts\UploadField;
use Dcat\Admin\Form\Field\Embeds;
use Dcat\Admin\Form\Field\File;
use Dcat\Admin\Form\Field\HasMany;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Support\Helper;
use Dcat\Admin\Traits\HasUploadedFile;
use Dcat\Admin\Widgets\Form;
use Illuminate\Http\Request;

class HandleFormController
{
    use HasUploadedFile;

    public function handle(Request $request)
    {
        $form = $this->resolveForm($request);

        if (! $form->passesAuthorization()) {
            return $form->failedAuthorization();
        }

        $form->form();

        if ($errors = $form->validate($request)) {
            return $form->validationErrorsResponse($errors);
        }

        $input = $form->sanitize($request->all());

        return $this->sendResponse($form->handle($input));
    }

    public function uploadFile(Request $request)
    {
        $form = $this->resolveForm($request);

        if (! $form->passesAuthorization()) {
            return $form->failedAuthorization();
        }

        $form->form();

        $field = $this->getField($request, $form);
        abort_unless($field instanceof UploadField, 400, 'Invalid upload field.');

        return $field->upload($this->file());
    }

    /**
     * @param  Request  $request
     * @param $form
     * @return File|null
     */
    protected function getField(Request $request, $form)
    {
        $column = $this->uploader()->upload_column ?: $request->get('_column');

        if (! $relation = $request->get('_relation')) {
            return $form->field($column);
        }

        $relation = is_array($relation) ? current($relation) : $relation;

        $relationField = $form->field($relation);

        if (! $relationField) {
            return;
        }

        if ($relationField instanceof HasMany) {
            return $relationField->buildNestedForm()->field($column);
        }
        if ($relationField instanceof Embeds) {
            return $relationField->field($column);
        }
    }

    public function destroyFile(Request $request)
    {
        $form = $this->resolveForm($request);

        if (! $form->passesAuthorization()) {
            return $form->failedAuthorization();
        }

        $form->form();

        $field = $this->getField($request, $form);
        abort_unless($field instanceof UploadField, 400, 'Invalid upload field.');

        abort_unless(method_exists($field, 'assertCanDeleteFiles'), 403, 'This upload field does not support secure deletion.');
        $field->setOriginal($form->data()->toArray());
        $field->assertCanDeleteFiles($request->key);
        $field->deleteFile($request->key);

        return $this->responseDeleted();
    }

    /**
     * @param  Request  $request
     * @return Form
     *
     * @throws AdminException
     */
    protected function resolveForm(Request $request)
    {
        $formClass = $request->get(Form::REQUEST_NAME);
        abort_unless(Helper::validateClassName($formClass) && is_subclass_of($formClass, Form::class), 400, 'Invalid form class.');

        /** @var Form $form */
        $form = app($formClass);

        if (! method_exists($form, 'handle')) {
            throw new AdminException("Form method {$formClass}::handle() does not exist.");
        }

        return $form;
    }

    protected function sendResponse($response)
    {
        if ($response instanceof JsonResponse) {
            return $response->send();
        }

        return $response;
    }
}
