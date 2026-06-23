<?php

use Lalalili\AudienceCore\Models\AudienceList;
use Lalalili\AudienceCore\Models\AudienceListRow;
use Lalalili\SurveyCore\Actions\SyncAudienceListToSurveyRecipientsAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

it('syncs active audience rows into survey recipients and generates tokens', function () {
    $audienceList = AudienceList::create([
        'name' => '問卷個性化名單',
        'columns_json' => ['name', 'email', 'member_no'],
    ]);

    $activeRow = AudienceListRow::create([
        'audience_list_id' => $audienceList->id,
        'external_id' => 'row-1',
        'data_json' => [
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'member_no' => 'M-001',
        ],
        'status' => 'active',
    ]);

    AudienceListRow::create([
        'audience_list_id' => $audienceList->id,
        'external_id' => 'row-2',
        'data_json' => [
            'name' => 'Inactive',
            'email' => 'inactive@example.com',
            'member_no' => 'M-002',
        ],
        'status' => 'inactive',
    ]);

    $survey = Survey::create([
        'title' => 'Audience Survey',
        'status' => SurveyStatus::Published,
        'settings_json' => [
            'personalization' => [
                'audience_list_id' => $audienceList->id,
                'name_column' => 'name',
                'email_column' => 'email',
                'external_id_column' => 'member_no',
                'field_mappings' => [
                    'member' => 'member_no',
                ],
            ],
        ],
    ]);

    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Hidden,
        'label' => 'Member',
        'field_key' => 'member',
        'is_hidden' => true,
        'sort_order' => 1,
    ]);

    $synced = app(SyncAudienceListToSurveyRecipientsAction::class)->execute($survey);

    $recipient = SurveyRecipient::query()
        ->where('survey_id', $survey->id)
        ->where('audience_list_row_id', $activeRow->id)
        ->first();

    expect($synced)->toBe(1)
        ->and($recipient)->not->toBeNull()
        ->and($recipient->name)->toBe('Tester')
        ->and($recipient->email)->toBe('tester@example.com')
        ->and($recipient->external_id)->toBe('M-001')
        ->and($recipient->payload_json)->toMatchArray(['member_no' => 'M-001'])
        ->and($recipient->status)->toBe(SurveyRecipientStatus::Active)
        ->and(SurveyRecipient::query()->where('survey_id', $survey->id)->count())->toBe(1)
        ->and(SurveyToken::query()->where('survey_recipient_id', $recipient->id)->count())->toBe(1)
        ->and($field->fresh()->is_personalized)->toBeTrue()
        ->and($field->fresh()->personalized_key)->toBe('member_no');
});
