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
        $synced = 0;

        // 本動作只同步收件人，不再改寫欄位定義：哪些題目是個性化欄位、對應名單的哪個
        // 鍵，唯一來源是 builder 的逐題 personalized_key（發佈時由
        // SyncSurveyBuilderSchemaToFieldsAction 寫入）。先前這裡會依
        // settings_json.personalization.field_mappings 覆寫，兩條路徑互相蓋來蓋去。
        DB::transaction(function () use ($audienceList, $survey, $generateTokens, $emailColumn, $nameColumn, $externalIdColumn, &$synced): void {
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
