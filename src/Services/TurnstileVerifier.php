<?php

namespace Lalalili\SurveyCore\Services;

use Illuminate\Support\Facades\Http;

class TurnstileVerifier
{
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! config('survey-core.security.turnstile_verify', true)) {
            return true;
        }

        $secret = config('survey-core.turnstile.secret_key');

        if (! is_string($secret) || $secret === '' || ! is_string($token) || $token === '') {
            return false;
        }

        $response = Http::asForm()
            ->timeout(5)
            ->connectTimeout(3)
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);

        return $response->ok() && $response->json('success') === true;
    }
}
