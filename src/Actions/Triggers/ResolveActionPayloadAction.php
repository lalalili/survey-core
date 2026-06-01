<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Lalalili\SurveyCore\Models\SurveyResponse;

class ResolveActionPayloadAction
{
    /**
     * Replace template tokens in a payload template with actual values.
     *
     * Supported tokens:
     *   {{response.<attr>}}          — SurveyResponse attribute (id, survey_id, submitted_at, …)
     *   {{answer.<field_key>}}       — answer value for the given field key
     *   {{recipient.<attr>}}         — SurveyRecipient attribute (external_id, email, name)
     *   {{recipient.payload.<key>}}  — recipient payload_json value (原始名單欄位，如 mobile / license_plate)
     *   {{env.<KEY>}}                — environment variable (read at job-execution time; never stored)
     *
     * @param  array<string, mixed>  $template  Nested array from actions_json.payload_template
     * @param  array<string, mixed>  $answerMap  field_key => value
     * @return array<string, mixed>
     */
    public function execute(array $template, SurveyResponse $response, array $answerMap): array
    {
        return $this->resolveNode($template, $response, $answerMap);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $answerMap
     * @return array<string, mixed>
     */
    private function resolveNode(array $node, SurveyResponse $response, array $answerMap): array
    {
        $resolved = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $resolved[$key] = $this->resolveNode($value, $response, $answerMap);
            } else {
                $resolved[$key] = $this->resolveToken((string) $value, $response, $answerMap);
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $answerMap
     */
    private function resolveToken(string $value, SurveyResponse $response, array $answerMap): string
    {
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function (array $matches) use ($response, $answerMap): string {
                $token = trim($matches[1]);

                if (str_starts_with($token, 'response.')) {
                    $attr = substr($token, 9);

                    return (string) ($response->$attr ?? '');
                }

                if (str_starts_with($token, 'answer.')) {
                    $key = substr($token, 7);
                    $val = $answerMap[$key] ?? '';

                    return is_array($val) ? implode(',', $val) : (string) $val;
                }

                if (str_starts_with($token, 'recipient.payload.')) {
                    $key = substr($token, 18);
                    $payload = $response->recipient?->payload_json;
                    $val = is_array($payload) ? ($payload[$key] ?? '') : '';

                    return is_array($val) ? implode(',', $val) : (string) $val;
                }

                if (str_starts_with($token, 'recipient.')) {
                    $attr = substr($token, 10);
                    $recipient = $response->recipient;

                    return $recipient !== null ? (string) ($recipient->$attr ?? '') : '';
                }

                if (str_starts_with($token, 'env.')) {
                    $envKey = substr($token, 4);

                    return (string) ($_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey) ?: '');
                }

                return $matches[0];
            },
            $value,
        ) ?? $value;
    }
}
