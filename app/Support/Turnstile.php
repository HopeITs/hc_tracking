<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile: the "are you a human" check on the login form.
 *
 * The widget on the page puts a one-time token in the form; passes() asks
 * Cloudflare whether that token is genuine before the HR password is looked
 * at, so a script cannot guess passwords (or lock someone's email out) without
 * solving the check first.
 *
 * It is off until both keys are configured. When Cloudflare cannot be reached,
 * or rejects the secret key itself, the check lets the login through and says
 * so in the auth log: an outage or a mistyped key must not lock every employee
 * out, and the login rate limits and two-factor still apply. A caller cannot
 * cause either condition.
 */
final class Turnstile
{
    /**
     * The form field the widget writes its token into.
     */
    public const ResponseField = 'cf-turnstile-response';

    /**
     * Where the widget's script and frame are served from (for the CSP).
     */
    public const Origin = 'https://challenges.cloudflare.com';

    private const VerifyUrl = self::Origin.'/turnstile/v0/siteverify';

    /**
     * Cloudflare never issues a token longer than this.
     */
    private const MaxTokenLength = 2048;

    private const TimeoutSeconds = 5;

    /**
     * A token can be verified only once, and Fortify runs the login callback
     * twice in one request (the two-factor step, then the login itself), so
     * the answer is remembered on the request.
     */
    private const PassedAttribute = 'turnstile.passed';

    public static function isEnabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    /**
     * Whether this request carries a token Cloudflare vouches for.
     */
    public static function passes(Request $request): bool
    {
        if (! self::isEnabled() || $request->attributes->get(self::PassedAttribute) === true) {
            return true;
        }

        $token = $request->input(self::ResponseField);

        if (! is_string($token) || $token === '' || strlen($token) > self::MaxTokenLength) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(self::TimeoutSeconds)
                ->timeout(self::TimeoutSeconds)
                ->post(self::VerifyUrl, [
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => (string) $request->ip(),
                ]);
        } catch (ConnectionException) {
            return self::passWithoutAnswer($request, 'unreachable');
        }

        $errorCodes = array_values(array_filter((array) $response->json('error-codes'), is_string(...)));

        if (! is_bool($response->json('success'))) {
            return self::passWithoutAnswer($request, 'status '.$response->status());
        }

        if (array_intersect($errorCodes, ['missing-input-secret', 'invalid-input-secret', 'internal-error']) !== []) {
            return self::passWithoutAnswer($request, implode(',', $errorCodes));
        }

        if ($response->json('success') !== true) {
            Log::channel('auth')->info('turnstile_failed', ['ip' => $request->ip(), 'errors' => $errorCodes]);

            return false;
        }

        $request->attributes->set(self::PassedAttribute, true);

        return true;
    }

    /**
     * Let the login through because Cloudflare gave no usable answer.
     */
    private static function passWithoutAnswer(Request $request, string $reason): bool
    {
        Log::channel('auth')->critical('turnstile_unavailable', ['ip' => $request->ip(), 'reason' => $reason]);

        $request->attributes->set(self::PassedAttribute, true);

        return true;
    }
}
