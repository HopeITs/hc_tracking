<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    /**
     * Always land signed-in users on the home page, never on wherever they were before signing out.
     */
    public function toResponse($request): Response
    {
        if ($request instanceof Request && $request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $request->session()->forget('url.intended');

        return redirect()->to(Fortify::redirects('login'));
    }
}
