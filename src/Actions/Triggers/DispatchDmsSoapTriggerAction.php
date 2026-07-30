<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Lalalili\SurveyCore\Enums\DmsExecutionMode;
use Lalalili\SurveyCore\Enums\SurveyTriggerActionAttemptStatus;
use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;
use Lalalili\SurveyCore\Exceptions\DmsConfigurationException;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;
use Throwable;

final class DispatchDmsSoapTriggerAction
{
    public function __construct(
        private readonly ValidateDmsActionConfiguration $validateConfiguration,
        private readonly BuildDmsSoapEnvelope $envelopes,
        private readonly ParseDmsSoapResponse $responses,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $parameters
     */
    public function execute(
        array $action,
        array $parameters,
        DmsExecutionMode $mode,
        string $actionKey,
        ?SurveyTriggerDispatch $dispatch = null,
        ?int $presetId = null,
        ?int $initiatedBy = null,
    ): SurveyTriggerActionAttempt {
        if ($mode === DmsExecutionMode::Automatic
            && ! (bool) config('external-communications.enabled', true)) {
            return $this->recordSkippedAttempt(
                $action,
                $parameters,
                $actionKey,
                $dispatch,
                $presetId,
                $initiatedBy,
            );
        }

        $this->validateConfiguration->execute($action, $mode);

        if ($mode === DmsExecutionMode::ManualQa
            && ! (bool) config('survey-core.triggers.dms.manual_test_enabled', false)) {
            throw new DmsConfigurationException('Manual DMS QA is disabled.');
        }

        $profile = (string) $action['profile'];
        $profileConfig = config("survey-core.triggers.dms.profiles.{$profile}");
        $endpoint = (string) $profileConfig['endpoint'];
        $key = (string) $profileConfig['key'];
        $soapAction = (string) ($profileConfig['soap_action'] ?? '');
        $xml = $this->envelopes->execute($key, $parameters);

        $attempt = SurveyTriggerActionAttempt::create([
            'survey_trigger_action_preset_id' => $presetId,
            'survey_trigger_dispatch_id' => $dispatch?->getKey(),
            'action_key' => $actionKey,
            'action_type' => 'dms_soap',
            'mode' => $mode->value,
            'profile' => $profile,
            'status' => SurveyTriggerActionAttemptStatus::PendingReview,
            'ticket_no' => $parameters['ticketno'] ?? null,
            'endpoint' => $endpoint,
            'request_parameters' => $parameters,
            'request_body' => $this->envelopes->redactKey($xml),
            'initiated_by' => $initiatedBy,
        ]);

        $startedAt = hrtime(true);

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/xml',
                'SOAPAction' => $soapAction,
            ])
                ->connectTimeout((int) config('survey-core.triggers.dms.connect_timeout', 5))
                ->timeout((int) config('survey-core.triggers.dms.timeout', 15))
                ->withBody($xml, 'text/xml; charset=utf-8')
                ->post($endpoint);
            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $result = $this->responses->execute(
                $response->body(),
                $response->status(),
                $this->successCodes($action),
                $this->emptyResponseIsConfirmedSuccess($action, $profile),
            );

            $attempt->update([
                'status' => $result->status,
                'response_http_status' => $response->status(),
                'response_headers' => $response->headers(),
                'response_body' => $response->body(),
                'parsed_response' => $result->parsed,
                'duration_ms' => $duration,
                'error' => $result->error,
                'sent_at' => now(),
            ]);

            $this->updateDispatch($dispatch, $result->status, $result->parsed, $result->error);
        } catch (ConnectionException $exception) {
            $attempt->update([
                'status' => SurveyTriggerActionAttemptStatus::ConnectionError,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'error' => $exception->getMessage(),
                'sent_at' => now(),
            ]);
            $this->updateDispatch(
                $dispatch,
                SurveyTriggerActionAttemptStatus::ConnectionError,
                [],
                $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $attempt->update([
                'status' => SurveyTriggerActionAttemptStatus::HttpError,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'error' => $exception->getMessage(),
                'sent_at' => now(),
            ]);
            $this->updateDispatch(
                $dispatch,
                SurveyTriggerActionAttemptStatus::HttpError,
                [],
                $exception->getMessage(),
            );
        }

        return $attempt->refresh();
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $parameters
     */
    private function recordSkippedAttempt(
        array $action,
        array $parameters,
        string $actionKey,
        ?SurveyTriggerDispatch $dispatch,
        ?int $presetId,
        ?int $initiatedBy,
    ): SurveyTriggerActionAttempt {
        $profile = (string) ($action['profile'] ?? 'production');
        $endpoint = (string) config("survey-core.triggers.dms.profiles.{$profile}.endpoint", '');
        $error = 'DMS action disabled by external communications setting.';

        $attempt = SurveyTriggerActionAttempt::create([
            'survey_trigger_action_preset_id' => $presetId,
            'survey_trigger_dispatch_id' => $dispatch?->getKey(),
            'action_key' => $actionKey,
            'action_type' => 'dms_soap',
            'mode' => DmsExecutionMode::Automatic->value,
            'profile' => $profile,
            'status' => SurveyTriggerActionAttemptStatus::Skipped,
            'ticket_no' => $parameters['ticketno'] ?? null,
            'endpoint' => filled($endpoint) ? $endpoint : '[disabled]',
            'request_parameters' => $parameters,
            'request_body' => '',
            'error' => $error,
            'initiated_by' => $initiatedBy,
        ]);

        $dispatch?->update([
            'status' => TriggerDispatchStatus::Skipped,
            'error' => $error,
            'dispatched_at' => now(),
        ]);

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<string>
     */
    private function successCodes(array $action): array
    {
        $configured = $action['success_error_codes']
            ?? config('survey-core.triggers.dms.success_error_codes', []);

        return is_array($configured)
            ? array_values(array_map('strval', $configured))
            : [];
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function emptyResponseIsConfirmedSuccess(array $action, string $profile): bool
    {
        return $profile === 'production'
            && ($action['empty_response_is_success'] ?? false) === true
            && data_get($action, 'parameter_confirmations.response_semantics') === 'confirmed';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function updateDispatch(
        ?SurveyTriggerDispatch $dispatch,
        SurveyTriggerActionAttemptStatus $status,
        array $parsed,
        ?string $error,
    ): void {
        if (! $dispatch instanceof SurveyTriggerDispatch) {
            return;
        }

        $dispatch->update([
            'status' => $status === SurveyTriggerActionAttemptStatus::Success
                ? TriggerDispatchStatus::Sent
                : TriggerDispatchStatus::Failed,
            'response_json' => $parsed,
            'error' => $error,
            'attempts' => $dispatch->attempts + 1,
            'dispatched_at' => now(),
        ]);
    }
}
