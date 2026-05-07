<?php

namespace Lalalili\SurveyCore\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lalalili\SurveyCore\Events\SurveySubmitted;

class DispatchSurveySubmittedWebhook implements ShouldQueue
{
    public int $tries;

    public int $timeout;

    public function __construct()
    {
        $this->tries = (int) config('survey-core.webhooks.tries', 3);
        $this->timeout = (int) config('survey-core.webhooks.timeout', 10);
    }

    public function handle(SurveySubmitted $event): void
    {
        $endpoints = config('survey-core.webhooks.endpoints', []);

        if (empty($endpoints)) {
            return;
        }

        $payload = $this->buildPayload($event);

        foreach ($endpoints as $endpoint) {
            $url = is_array($endpoint) ? ($endpoint['url'] ?? '') : (string) $endpoint;
            $secret = is_array($endpoint) ? ($endpoint['secret'] ?? null) : null;

            if (empty($url)) {
                continue;
            }

            $this->send($url, $secret, $payload);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $url, ?string $secret, array $payload): void
    {
        $json = json_encode($payload);

        if ($json === false) {
            return;
        }

        $headers = ['Content-Type' => 'application/json'];

        if ($secret !== null) {
            $headers['X-Survey-Signature'] = 'sha256='.hash_hmac('sha256', $json, $secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('survey-core webhook non-2xx', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
            }
        } catch (ConnectionException $e) {
            Log::error('survey-core webhook connection error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Re-throw so the queue retries the job
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(SurveySubmitted $event): array
    {
        $response = $event->response;
        $survey = $event->survey;
        $recipient = $event->recipient;

        $response->loadMissing('answers.field');

        $answers = $response->answers->mapWithKeys(function ($answer) {
            return [
                $answer->field->field_key => $answer->answer_json ?? $answer->answer_text,
            ];
        })->all();

        return [
            'event' => 'survey.submitted',
            'survey' => [
                'id' => $survey->id,
                'public_key' => $survey->public_key,
                'title' => $survey->title,
            ],
            'response' => [
                'id' => $response->id,
                'submitted_at' => $response->submitted_at?->toIso8601String(),
                'calculations' => $response->calculations_json,
            ],
            'recipient' => $recipient ? [
                'id' => $recipient->id,
                'email' => $recipient->email,
                'name' => $recipient->name,
            ] : null,
            'answers' => $answers,
            'calculations' => $response->calculations_json ?? [],
        ];
    }
}
