<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;
use Lalalili\SurveyCore\Models\SurveyTriggerAllowedHost;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;

class DispatchHttpTriggerAction
{
    /**
     * @param  array<string, mixed>  $action  Single action definition from actions_json
     * @param  array<string, mixed>  $resolvedPayload
     */
    public function execute(SurveyTriggerDispatch $dispatch, array $action, array $resolvedPayload): void
    {
        $endpoint = $action['endpoint'] ?? '';
        $headers = $this->resolveHeaders($action['headers'] ?? []);
        $timeout = (int) ($action['timeout'] ?? 10);
        $retryTimes = (int) ($action['retry']['times'] ?? 3);
        $retrySleep = (int) ($action['retry']['sleep_ms'] ?? 200);

        if (! (bool) config('external-communications.enabled', true)) {
            $dispatch->update([
                'status' => TriggerDispatchStatus::Skipped,
                'payload_json' => $resolvedPayload,
                'response_json' => [
                    'status' => 'skipped',
                    'endpoint' => $endpoint,
                ],
                'error' => 'HTTP trigger disabled by external communications setting.',
                'dispatched_at' => now(),
            ]);

            Log::info('survey-trigger skipped by external communications setting', [
                'dispatch_id' => $dispatch->id,
                'endpoint' => $endpoint,
            ]);

            return;
        }

        if ($this->isHostBlocked($endpoint)) {
            $host = parse_url($endpoint, PHP_URL_HOST) ?? $endpoint;
            $dispatch->update([
                'status' => TriggerDispatchStatus::Failed,
                'error' => "Endpoint host '{$host}' is not in the allowed hosts list.",
                'dispatched_at' => now(),
            ]);
            Log::warning('survey-trigger blocked host', ['dispatch_id' => $dispatch->id, 'host' => $host]);

            return;
        }

        $dispatch->increment('attempts');
        $dispatch->payload_json = $resolvedPayload;
        $dispatch->save();

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->retry($retryTimes, $retrySleep)
                ->post($endpoint, $resolvedPayload);

            $dispatch->response_json = ['status' => $response->status(), 'body' => $response->json()];
            $dispatch->dispatched_at = now();

            if ($response->successful()) {
                $dispatch->status = TriggerDispatchStatus::Sent;
                $dispatch->error = null;
            } else {
                $dispatch->status = TriggerDispatchStatus::Failed;
                $dispatch->error = "HTTP {$response->status()}";

                Log::warning('survey-trigger http non-2xx', [
                    'dispatch_id' => $dispatch->id,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);
            }
        } catch (ConnectionException|RequestException $e) {
            $dispatch->status = TriggerDispatchStatus::Failed;
            $dispatch->error = $e->getMessage();

            Log::error('survey-trigger http error', [
                'dispatch_id' => $dispatch->id,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $dispatch->save();
        }
    }

    /**
     * Returns true if an allowed-hosts list exists and this endpoint's host is not on it.
     */
    private function isHostBlocked(string $endpoint): bool
    {
        $allowed = SurveyTriggerAllowedHost::pluck('host')->all();
        if (empty($allowed)) {
            return false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST) ?? '';

        return ! in_array($host, $allowed, true);
    }

    /**
     * Resolve {{env.*}} tokens in header values.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function resolveHeaders(array $headers): array
    {
        return array_map(function (string $value): string {
            return preg_replace_callback('/\{\{env\.([^}]+)\}\}/', function (array $matches): string {
                $key = trim((string) $matches[1]);

                if (! $this->isAllowedHeaderEnvKey($key)) {
                    return '';
                }

                return (string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: '');
            }, $value) ?? $value;
        }, $headers);
    }

    private function isAllowedHeaderEnvKey(string $key): bool
    {
        $allowedKeys = config('survey-core.triggers.header_env_keys', []);

        return is_array($allowedKeys) && in_array($key, $allowedKeys, true);
    }
}
