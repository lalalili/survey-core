<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Lalalili\SurveyCore\Contracts\DmsCaseRecorder;
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
        private readonly DmsCaseRecorder $caseRecorder,
    ) {
    }

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

        try {
            $this->validateConfiguration->execute($action, $mode);
        } catch (DmsConfigurationException $exception) {
            // 自動觸發沒有人在旁邊看例外，必須留下後台看得到的稽核列，否則案件會默默不見。
            if ($mode === DmsExecutionMode::Automatic) {
                return $this->recordConfigurationError(
                    $action,
                    $parameters,
                    $actionKey,
                    $exception->getMessage(),
                    $dispatch,
                    $presetId,
                    $initiatedBy,
                );
            }

            throw $exception;
        }

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
            $this->recordCase($dispatch, $attempt->refresh());
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
        return $this->recordUnsentAttempt(
            $action,
            $parameters,
            $actionKey,
            SurveyTriggerActionAttemptStatus::Skipped,
            TriggerDispatchStatus::Skipped,
            'DMS action disabled by external communications setting.',
            $dispatch,
            $presetId,
            $initiatedBy,
        );
    }

    /**
     * 記錄一次「因設定不完整而根本沒送出」的嘗試。設定類錯誤若只丟例外，
     * 後台會完全看不到案件失敗，因此一律落成稽核列。
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $parameters
     */
    public function recordConfigurationError(
        array $action,
        array $parameters,
        string $actionKey,
        string $error,
        ?SurveyTriggerDispatch $dispatch = null,
        ?int $presetId = null,
        ?int $initiatedBy = null,
    ): SurveyTriggerActionAttempt {
        return $this->recordUnsentAttempt(
            $action,
            $parameters,
            $actionKey,
            SurveyTriggerActionAttemptStatus::ConfigurationError,
            TriggerDispatchStatus::Failed,
            $error,
            $dispatch,
            $presetId,
            $initiatedBy,
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $parameters
     */
    private function recordUnsentAttempt(
        array $action,
        array $parameters,
        string $actionKey,
        SurveyTriggerActionAttemptStatus $status,
        TriggerDispatchStatus $dispatchStatus,
        string $error,
        ?SurveyTriggerDispatch $dispatch,
        ?int $presetId,
        ?int $initiatedBy,
    ): SurveyTriggerActionAttempt {
        $profile = (string) ($action['profile'] ?? 'production');
        $endpoint = (string) config("survey-core.triggers.dms.profiles.{$profile}.endpoint", '');

        $attempt = SurveyTriggerActionAttempt::create([
            'survey_trigger_action_preset_id' => $presetId,
            'survey_trigger_dispatch_id' => $dispatch?->getKey(),
            'action_key' => $actionKey,
            'action_type' => 'dms_soap',
            'mode' => DmsExecutionMode::Automatic->value,
            'profile' => $profile,
            'status' => $status,
            'ticket_no' => $parameters['ticketno'] ?? null,
            'endpoint' => filled($endpoint) ? $endpoint : '[disabled]',
            'request_parameters' => $parameters,
            'request_body' => '',
            'error' => $error,
            'initiated_by' => $initiatedBy,
        ]);

        $dispatch?->update([
            'status' => $dispatchStatus,
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
     * 立案成功後交由宿主應用記錄案件（QA 手動測試沒有 dispatch，因此不會寫入）。
     */
    private function recordCase(?SurveyTriggerDispatch $dispatch, SurveyTriggerActionAttempt $attempt): void
    {
        if (! $dispatch instanceof SurveyTriggerDispatch
            || $attempt->status !== SurveyTriggerActionAttemptStatus::Success) {
            return;
        }

        $this->caseRecorder->record($dispatch->response, $attempt);
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
