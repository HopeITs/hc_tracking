<?php

namespace App\Concerns;

use App\Actions\Fortify\AuthenticateWithHrAccount;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * The current password is the HR password, so it is checked against HR
     * rather than with Laravel's `current_password` rule.
     *
     * @return array<int, Password|ValidationRule|Closure|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
            $user = Auth::user();

            if (! $user instanceof User || ! AuthenticateWithHrAccount::confirmsPassword($user, is_string($value) ? $value : null)) {
                $fail(__('validation.current_password'));
            }
        }];
    }
}
