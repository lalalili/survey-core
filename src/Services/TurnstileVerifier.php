<?php

namespace Lalalili\SurveyCore\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TurnstileVerifier
{
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! config('survey-core.security.turnstile_verify', true)) {
            return true;
        }

        $secret = config('survey-core.turnstile.secret_key');

        if (! is_string($secret) || $secret === '') {
            // Misconfiguration trap: a survey enabled Turnstile but the server
            // secret is missing, so every submission would be silently blocked.
            Log::warning('Turnstile enabled but secret_key is not configured; submission blocked.', [
                'ip' => $ip,
            ]);

            return false;
        }

        if (! is_string($token) || $token === '') {
            Log::warning('Turnstile token missing from submission.', ['ip' => $ip]);

            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);
        } catch (Throwable $e) {
            Log::warning('Turnstile verification request failed.', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $success = $response->ok() && $response->json('success') === true;

        if (! $success) {
            Log::warning('Turnstile verification rejected.', [
                'ip' => $ip,
                'status' => $response->status(),
                'error_codes' => $response->json('error-codes'),
            ]);
        }

        return $success;
    }
}
