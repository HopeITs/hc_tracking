<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Re-checks the password confirmation inside Livewire actions on the
 * security settings page.
 *
 * The page route sits behind the password.confirm middleware, but Livewire
 * only re-runs auth and authorization middleware on its own update requests,
 * never password.confirm. Without this check, anyone holding a signed-in
 * session could replay a snapshot of the page and switch off two-factor
 * authentication, delete a passkey or read fresh recovery codes without
 * knowing the password. The window is the same one RequirePassword uses.
 */
trait RequiresConfirmedPassword
{
    /**
     * Whether the password was confirmed within auth.password_timeout.
     */
    protected function passwordRecentlyConfirmed(): bool
    {
        $secondsSinceConfirmation = time() - (int) session('auth.password_confirmed_at', 0);

        return $secondsSinceConfirmation <= (int) config('auth.password_timeout', 10800);
    }

    /**
     * Let the action go ahead when the password was confirmed recently;
     * otherwise send the person to confirm it and bring them back to the
     * security page afterwards.
     */
    protected function ensurePasswordIsConfirmed(): bool
    {
        if ($this->passwordRecentlyConfirmed()) {
            return true;
        }

        session()->put('url.intended', route('security.edit'));

        $this->redirectRoute('password.confirm');

        return false;
    }
}
