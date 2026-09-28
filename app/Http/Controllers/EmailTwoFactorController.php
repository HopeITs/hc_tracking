<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fortify\EmailLoginCodes;
use App\Actions\Fortify\RedirectIfTwoFactorRequired;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second login step for email two-factor authentication: the person has
 * given the right HR password and now types the code emailed to them (see
 * RedirectIfTwoFactorRequired).
 */
class EmailTwoFactorController extends Controller
{
    /**
     * Show the code form.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if ($this->challengedUser($request) === null) {
            return redirect()->route('login');
        }

        return view('pages::auth.email-two-factor-challenge');
    }

    /**
     * Sign the person in when the code is right.
     *
     * @throws ValidationException
     */
    public function store(Request $request): Response
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $code = $request->validate(['code' => ['required', 'string']])['code'];

        if (! EmailLoginCodes::verify($user, trim($code))) {
            event(new TwoFactorAuthenticationFailed($user));

            throw ValidationException::withMessages(['code' => [__('authentication.email_code_invalid')]]);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', RedirectIfTwoFactorRequired::EmailChallengeSessionKey]);

        Auth::guard('web')->login($user, $remember);

        $request->session()->regenerate();

        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        return app(TwoFactorLoginResponse::class)->toResponse($request);
    }

    /**
     * Email a fresh code (replacing the old one).
     */
    public function resend(Request $request): RedirectResponse
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! EmailLoginCodes::send($user)) {
            return back()->withErrors(['code' => __('authentication.email_code_not_sent')]);
        }

        return back()->with('status', __('authentication.email_code_resent'));
    }

    /**
     * The user whose password was just checked and who is waiting for an
     * emailed code, if any.
     */
    private function challengedUser(Request $request): ?User
    {
        if (! $request->session()->get(RedirectIfTwoFactorRequired::EmailChallengeSessionKey)) {
            return null;
        }

        $user = User::query()->find($request->session()->get('login.id'));

        return $user?->usesEmailTwoFactor() ? $user : null;
    }
}
