<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\SwitchLocaleRequest;
use Illuminate\Http\RedirectResponse;

class SwitchLocaleController extends Controller
{
    /**
     * Switch the interface language and go back to the same page.
     *
     * The choice is saved on the signed-in user so it follows them to other
     * devices, and in the session so guests keep it too.
     */
    public function __invoke(SwitchLocaleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->session()->put(SetLocale::SessionKey, $validated['locale']);
        $request->user()?->forceFill(['locale' => $validated['locale']])->save();

        return redirect()->back();
    }
}
