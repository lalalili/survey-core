<?php

namespace Lalalili\SurveyCore\Integrations\EmailCampaign;

use Lalalili\AudienceCore\Models\AudienceListRow;
use Lalalili\EmailCampaign\Contracts\VariableProvider;
use Lalalili\EmailCampaign\Models\EmailCampaign;
use Lalalili\EmailCampaign\Models\EmailCampaignRecipient;
use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyTokenStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;
use RuntimeException;

/**
 * Bridges email-campaign with survey-core by implementing the VariableProvider contract.
 *
 * Provides: survey_url, survey_title, survey_public_key
 *
 * Campaign must have survey_id set.
 * Recipient must have external_id matching a SurveyRecipient.external_id.
 */
class SurveyVariableProvider implements VariableProvider
{
    public function __construct(private GenerateSurveyTokenAction $generateToken)
    {
    }

    /**
     * @return array<string, scalar|null>
     */
    public function variablesFor(EmailCampaign $campaign, EmailCampaignRecipient $recipient): array
    {
        $surveyId = $campaign->survey_id;

        if (! $surveyId) {
            return [];
        }

        $survey = Survey::query()->find((int) $surveyId);

        if (! $survey) {
            throw new RuntimeException("Survey [{$surveyId}] was not found.");
        }

        if (! $survey->isAcceptingSubmissions()) {
            throw new RuntimeException("Survey [{$survey->id}] is not accepting submissions.");
        }

        $vars = [
            'survey_title' => $survey->title,
            'survey_public_key' => $survey->public_key,
            'survey_url' => route('survey.show', $survey->public_key),
        ];

        $surveyRecipient = null;

        $audienceListRowId = $recipient->audience_list_row_id;

        if (! empty($audienceListRowId)) {
            $surveyRecipient = SurveyRecipient::where('survey_id', $survey->id)
                ->where('audience_list_row_id', $audienceListRowId)
                ->first();

            if (! $surveyRecipient) {
                $audienceRow = AudienceListRow::query()->find((int) $audienceListRowId);

                if ($audienceRow) {
                    $surveyRecipient = SurveyRecipient::create([
                        'survey_id' => $survey->id,
                        'audience_list_row_id' => $audienceRow->id,
                        'name' => $recipient->user_name,
                        'email' => $recipient->email,
                        'external_id' => (string) $audienceRow->id,
                        'payload_json' => $audienceRow->data_json ?? [],
                        'status' => SurveyRecipientStatus::Active,
                    ]);
                }
            }
        }

        // Personalise URL with a token when external_id matches a SurveyRecipient.
        $externalId = $recipient->external_id;

        if (! empty($externalId)) {
            $surveyRecipient ??= SurveyRecipient::where('survey_id', $survey->id)
                ->where('external_id', $externalId)
                ->first();
        }

        if ($surveyRecipient) {
            $token = $this->usableToken($surveyRecipient) ?? $this->generateToken->execute($survey, $surveyRecipient);
            $vars['survey_url'] = route('survey.show', $survey->public_key).'?t='.$token->token;
        }

        return $vars;
    }

    private function usableToken(SurveyRecipient $recipient): ?SurveyToken
    {
        return $recipient->tokens()
            ->where('status', SurveyTokenStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($query): void {
                $query->whereNull('max_submissions')
                    ->orWhereColumn('used_count', '<', 'max_submissions');
            })
            ->latest('id')
            ->first();
    }
}
