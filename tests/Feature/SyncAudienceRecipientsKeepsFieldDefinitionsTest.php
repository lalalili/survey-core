<?php

use Lalalili\AudienceCore\Models\AudienceList;
use Lalalili\AudienceCore\Models\AudienceListRow;
use Lalalili\SurveyCore\Actions\SyncAudienceListToSurveyRecipientsAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;

function personalizedSurveyWithList(): array
{
    $list = AudienceList::create([
        'name' => '車主名單',
        'columns_json' => [
            ['key' => 'owner_name', 'label' => '車主姓名', 'type' => 'string'],
            ['key' => 'dlrcode', 'label' => '經銷商', 'type' => 'string'],
        ],
    ]);

    AudienceListRow::create([
        'audience_list_id' => $list->id,
        'external_id' => 'o1',
        'data_json' => ['owner_name' => '王小明', 'dlrcode' => 'LB'],
        'status' => 'active',
    ]);

    $survey = Survey::create([
        'title' => '個性化問卷',
        'status' => SurveyStatus::Published,
        'settings_json' => [
            'personalization' => [
                'audience_list_id' => $list->id,
                'name_column' => 'owner_name',
            ],
        ],
    ]);

    // builder 逐題設定：這是個性化鍵值的唯一來源。
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Hidden,
        'label' => '經銷商',
        'field_key' => 'dealer',
        'is_hidden' => true,
        'is_personalized' => true,
        'personalized_key' => 'dlrcode',
        'sort_order' => 1,
    ]);

    return [$survey, $field];
}

it('syncs recipients without touching field definitions', function (): void {
    [$survey, $field] = personalizedSurveyWithList();

    $synced = app(SyncAudienceListToSurveyRecipientsAction::class)->execute($survey, generateTokens: false);

    expect($synced)->toBe(1)
        ->and(SurveyRecipient::where('survey_id', $survey->id)->count())->toBe(1);

    // 同步收件人不得改寫哪些題目是個性化欄位、對應名單的哪個鍵。
    $field->refresh();
    expect($field->personalized_key)->toBe('dlrcode')
        ->and($field->is_personalized)->toBeTrue();
});

it('leaves the builder key untouched even when legacy field_mappings linger in settings', function (): void {
    [$survey, $field] = personalizedSurveyWithList();

    // 舊資料可能仍帶著 field_mappings；它不該再影響任何行為。
    $settings = $survey->settings_json;
    $settings['personalization']['field_mappings'] = ['dealer' => 'owner_name'];
    $survey->forceFill(['settings_json' => $settings])->save();

    app(SyncAudienceListToSurveyRecipientsAction::class)->execute($survey->refresh(), generateTokens: false);

    expect($field->refresh()->personalized_key)->toBe('dlrcode');
});

it('carries the audience row payload onto the recipient', function (): void {
    [$survey] = personalizedSurveyWithList();

    app(SyncAudienceListToSurveyRecipientsAction::class)->execute($survey, generateTokens: false);

    $recipient = SurveyRecipient::where('survey_id', $survey->id)->first();

    expect($recipient->name)->toBe('王小明')
        ->and($recipient->payload_json['dlrcode'])->toBe('LB');
});
