<?php

namespace Lalalili\SurveyCore\Integrations\EmailCampaign;

use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Models\AudienceListRow;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;

/**
 * Optional VariableProvider that bridges email-campaign with survey-core.
 *
 * Register this in SurveyCoreServiceProvider::bootingPackage() only when
 * the email-campaign VariableProvider contract is available, so survey-core
 * does not hard-depend on email-campaign.
 *
 * Provides: survey_url, survey_title, survey_public_key
 *
 * Campaign must have extras_json['survey_id'] set.
 * Recipient must have external_id matching a SurveyRecipient.external_id.
 */
class SurveyVariableProvider
{
    public function __construct(private GenerateSurveyTokenAction $generateToken) {}

    public function variablesFor(object $campaign, object $recipient): array
    {
        $extras   = $campaign->extras_json ?? [];
        $surveyId = $extras['survey_id'] ?? null;

        if (! $surveyId) {
            return [];
        }

        $survey = Survey::find($surveyId);

        if (! $survey) {
            return [];
        }

        $vars = [
            'survey_title'      => $survey->title,
            'survey_public_key' => $survey->public_key,
            'survey_url'        => route('survey.show', $survey->public_key),
        ];

        $surveyRecipient = null;

        if (! empty($recipient->audience_list_row_id)) {
            $surveyRecipient = SurveyRecipient::where('survey_id', $survey->id)
                ->where('audience_list_row_id', $recipient->audience_list_row_id)
                ->first();

            if (! $surveyRecipient) {
                $audienceRow = AudienceListRow::find($recipient->audience_list_row_id);

                if ($audienceRow) {
                    $surveyRecipient = SurveyRecipient::create([
                        'survey_id'             => $survey->id,
                        'audience_list_row_id'  => $audienceRow->id,
                        'name'                  => $recipient->user_name ?? null,
                        'email'                 => $recipient->email ?? null,
                        'external_id'           => (string) $audienceRow->id,
                        'payload_json'          => $audienceRow->data_json ?? [],
                        'status'                => SurveyRecipientStatus::Active,
                    ]);
                }
            }
        }

        // Personalise URL with a token when external_id matches a SurveyRecipient.
        if (! empty($recipient->external_id)) {
            $surveyRecipient ??= SurveyRecipient::where('survey_id', $survey->id)
                ->where('external_id', $recipient->external_id)
                ->first();
        }

        if ($surveyRecipient) {
            $token              = $this->generateToken->execute($survey, $surveyRecipient);
            $vars['survey_url'] = route('survey.show', $survey->public_key) . '?t=' . $token->token;
        }

        return $vars;
    }
}
