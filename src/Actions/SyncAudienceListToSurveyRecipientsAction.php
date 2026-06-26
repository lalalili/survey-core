<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\AudienceCore\Models\AudienceList;
use Lalalili\AudienceCore\Models\AudienceListRow;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;

class SyncAudienceListToSurveyRecipientsAction
{
    public function __construct(private readonly GenerateSurveyTokenAction $generateToken) {}

    public function execute(Survey $survey, bool $generateTokens = true): int
    {
        $settings = $survey->settings();
        $listId = $settings->audienceListId;

        if (! $listId) {
            return 0;
        }

        $audienceList = AudienceList::with('rows')->find($listId);

        if (! $audienceList) {
            return 0;
        }

        $emailColumn = $settings->emailColumn ?? '';
        $nameColumn = $settings->nameColumn ?? '';
        $externalIdColumn = $settings->externalIdColumn ?? '';
        $fieldMappings = $survey->settings_json['personalization']['field_mappings'] ?? [];
        $synced = 0;

        DB::transaction(function () use ($audienceList, $survey, $generateTokens, $emailColumn, $nameColumn, $externalIdColumn, $fieldMappings, &$synced): void {
            if (is_array($fieldMappings)) {
                foreach ($fieldMappings as $fieldKey => $column) {
                    if (filled($fieldKey) && filled($column)) {
                        $survey->fields()
                            ->where('field_key', (string) $fieldKey)
                            ->where('is_hidden', true)
                            ->update([
                                'is_personalized' => true,
                                'personalized_key' => (string) $column,
                            ]);
                    }
                }
            }

            $audienceList->rows()
                ->where('status', 'active')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($survey, $generateTokens, $emailColumn, $nameColumn, $externalIdColumn, &$synced): void {
                    foreach ($rows as $row) {
                        /** @var AudienceListRow $row */
                        $data = $row->data_json ?? [];

                        $recipient = SurveyRecipient::updateOrCreate(
                            [
                                'survey_id' => $survey->id,
                                'audience_list_row_id' => $row->id,
                            ],
                            [
                                'name' => $nameColumn !== '' ? ($data[$nameColumn] ?? null) : null,
                                'email' => $emailColumn !== '' ? ($data[$emailColumn] ?? null) : null,
                                'external_id' => $externalIdColumn !== '' ? ($data[$externalIdColumn] ?? null) : (string) $row->id,
                                'payload_json' => $data,
                                'status' => SurveyRecipientStatus::Active,
                            ],
                        );

                        if ($generateTokens) {
                            $this->generateToken->execute($survey, $recipient);
                        }

                        $synced++;
                    }
                });
        });

        return $synced;
    }
}
