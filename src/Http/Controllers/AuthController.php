<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Http\Repositories\Administrator;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Traits\HasFormResponse;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use HasFormResponse;

    /**
     * @var string
     */
    protected $view = 'admin::pages.login';

    /**
     * @var string
     */
    protected $redirectTo;

    /**
     * Show the login page.
     *
     * @return Content|\Illuminate\Http\RedirectResponse
     */
    public function getLogin(Content $content)
    {
        if ($this->guard()->check()) {
            return redirect($this->getRedirectPath());
        }

        return $content->full()->body(view($this->view));
    }

    /**
     * Handle a login request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function postLogin(Request $request)
    {
        $credentials = $request->only([$this->username(), 'password']);
        $remember = (bool) $request->input('remember', false);

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($credentials, [
            $this->username()   => 'required|string|max:120',
            'password'          => 'required|string|max:4096',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorsResponse($validator);
        }

        $key = 'dcat-login:'.hash('sha256', Admin::app()->getName().'|'.Str::lower($credentials[$this->username()]).'|'.$request->ip());
        $attempts = max(1, (int) config('admin.auth.throttle.max_attempts', 5));
        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'status' => false,
                'message' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ], 429, ['Retry-After' => $seconds]);
        }

        if ($this->guard()->attempt($credentials, $remember)) {
            RateLimiter::clear($key);

            return $this->sendLoginResponse($request);
        }

        RateLimiter::hit($key, max(1, (int) config('admin.auth.throttle.decay_seconds', 60)));

        return $this->validationErrorsResponse([
            $this->username() => $this->getFailedLoginMessage(),
        ]);
    }

    /**
     * User logout.
     *
     * @return Redirect|string
     */
    public function getLogout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $path = admin_url('auth/login');
        if ($request->pjax()) {
            return "<script>location.href = '$path';</script>";
        }

        return redirect($path);
    }

    /**
     * User setting page.
     *
     * @param  Content  $content
     * @return Content
     */
    public function getSetting(Content $content)
    {
        $form = $this->settingForm();
        $form->tools(
            function (Form\Tools $tools) {
                $tools->disableList();
            }
        );

        return $content
            ->title(trans('admin.user_setting'))
            ->body($form->edit(Admin::user()->getKey()));
    }

    /**
     * Update user setting.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function putSetting()
    {
        $form = $this->settingForm();

        if (! $this->validateCredentialsWhenUpdatingPassword()) {
            $form->responseValidationMessages('old_password', trans('admin.old_password_error'));
        }

        return $form->update(Admin::user()->getKey());
    }

    protected function validateCredentialsWhenUpdatingPassword()
    {
        $user = Admin::user();

        $oldPassword = \request('old_password');
        $newPassword = \request('password');

        if (
            (! $newPassword)
            || ($newPassword === $user->getAuthPassword())
        ) {
            return true;
        }

        if (! $oldPassword) {
            return false;
        }

        return $this->guard()
            ->getProvider()
            ->validateCredentials($user, ['password' => $oldPassword]);
    }

    /**
     * Model-form for user setting.
     *
     * @return Form
     */
    protected function settingForm()
    {
        return new Form(new Administrator(), function (Form $form) {
            $form->action(admin_url('auth/setting'));

            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();

            $form->tools(function (Form\Tools $tools) {
                $tools->disableView();
                $tools->disableDelete();
            });

            $form->display('username', trans('admin.username'));
            $form->text('name', trans('admin.name'))->required();
            $form->image('avatar', trans('admin.avatar'))->autoUpload();

            $form->password('old_password', trans('admin.old_password'));

            $form->password('password', trans('admin.password'))
                ->minLength(5)
                ->maxLength(20)
                ->customFormat(function ($v) {
                    /** @var \Illuminate\Support\Fluent $this */
                    if ($v == $this->password) {
                        return;
                    }

                    return $v;
                });
            $form->password('password_confirmation', trans('admin.password_confirmation'))->same('password');

            $form->ignore(['password_confirmation', 'old_password']);

            $form->saving(function (Form $form) {
                if ($form->password && $form->model()->password != $form->password) {
                    $form->password = bcrypt($form->password);
                }

                if (! $form->password) {
                    $form->deleteInput('password');
                }
            });

            $form->saved(function (Form $form) {
                return $form
                    ->response()
                    ->success(trans('admin.update_succeeded'))
                    ->redirect('auth/setting');
            });
        });
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getFailedLoginMessage()
    {
        return Lang::has('admin.auth_failed')
            ? trans('admin.auth_failed')
            : 'These credentials do not match our records.';
    }

    /**
     * Get the post login redirect path.
     *
     * @return string
     */
    protected function getRedirectPath()
    {
        return $this->redirectTo ?: admin_url('/');
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function sendLoginResponse(Request $request)
    {
        $request->session()->regenerate();

        $path = $this->getRedirectPath();

        return $this->response()
            ->success(trans('admin.login_successful'))
            ->locationToIntended($path)
            ->locationIf(Admin::app()->getEnabledApps(), $path)
            ->send();
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    protected function username()
    {
        return 'username';
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard|GuardHelpers
     */
    protected function guard()
    {
        return Admin::guard();
    }
}
