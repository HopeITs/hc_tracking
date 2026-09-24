<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * Always land signed-in users on the home page, never on wherever they were before signing out.
     */
    public function toResponse($request): Response
    {
        if ($request instanceof Request && $request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $request->session()->forget('url.intended');

        return redirect()->to(Fortify::redirects('login'));
    }
}
