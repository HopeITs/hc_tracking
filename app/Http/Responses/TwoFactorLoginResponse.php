<?php

namespace App\Http\Responses;

use App\Actions\Fortify\TrustedDevices;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    /**
     * Always land signed-in users on the home page, never on wherever they were before signing out.
     * Trusts this browser first when "don't ask again on this device" was ticked.
     */
    public function toResponse($request): Response
    {
        if ($request->boolean(TrustedDevices::RememberField) && $request->user() instanceof User) {
            TrustedDevices::trust($request, $request->user());
        }

        if ($request instanceof Request && $request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $request->session()->forget('url.intended');

        return redirect()->to(Fortify::redirects('login'));
    }
}
