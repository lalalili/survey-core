<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Actions\RestoreSurveyPublishedSchemaAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyRecipientStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\AudienceCore\Models\AudienceList;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Support\SurveyResultContextFields;

beforeEach(function (): void {
    if (! Schema::hasColumn('audience_lists', 'schema_profile')) {
        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->string('schema_profile', 10)->nullable();
        });
    }
});

function resultContextAudienceList(string $profile = 'CSI', array $columns = []): AudienceList
{
    $list = AudienceList::create([
        'name' => $profile.' 名單',
        'columns_json' => $columns !== [] ? $columns : [
            ['key' => 'dlr', 'label' => '經銷商', 'type' => 'string'],
            ['key' => 'dept', 'label' => '據點', 'type' => 'string'],
            ['key' => 'regono', 'label' => '車牌', 'type' => 'string'],
            ['key' => 'timedelivered', 'label' => '交車日', 'type' => 'date'],
        ],
    ]);
    $list->forceFill(['schema_profile' => $profile])->save();

    return $list->refresh();
}

function resultContextSchema(?AudienceList $list, array $overrides = []): array
{
    $schema = [
        'title' => '售後服務問卷',
        'status' => 'draft',
        'version' => 1,
        'settings' => [
            'category' => 'CSI',
            'personalization' => [
                'audience_list_id' => $list?->id,
                'result_context_columns' => [
                    'dealer' => 'dlr',
                    'location' => 'dept',
                    'vehicle_plate' => 'regono',
                    'delivery_date' => 'timedelivered',
                ],
            ],
        ],
        'pages' => [[
            'id' => 'page_1',
            'kind' => 'question',
            'title' => '問卷題目',
            'elements' => [[
                'id' => 'q_score',
                'type' => 'short_text',
                'field_key' => 'score_note',
                'label' => '評分說明',
                'description' => '',
                'required' => false,
                'placeholder' => null,
                'options' => [],
                'settings' => [],
            ]],
        ]],
    ];

    return array_replace_recursive($schema, $overrides);
}

it('allows incomplete drafts and only synchronizes valid system context fields', function () {
    $list = resultContextAudienceList();
    $schema = resultContextSchema($list, [
        'settings' => ['personalization' => ['result_context_columns' => ['delivery_date' => null]]],
    ]);
    $survey = Survey::create(['title' => '草稿', 'status' => SurveyStatus::Draft]);

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);

    expect($saved->fields()->whereIn('field_key', SurveyResultContextFields::fieldKeys())->count())->toBe(0)
        ->and(collect($saved->draft_schema['pages'])->flatMap->elements
            ->whereIn('field_key', SurveyResultContextFields::fieldKeys()))->toHaveCount(3)
        ->and(data_get($saved->draft_schema, 'settings.personalization.result_context_columns.delivery_date'))->toBeNull();
});

it('synchronizes exactly four reserved fields idempotently across save publish and restore', function () {
    $list = resultContextAudienceList();
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);

    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, resultContextSchema($list));
    $savedAgain = app(SaveSurveyDraftSchemaAction::class)->execute($saved, $saved->draft_schema);
    $published = app(PublishSurveyAction::class)->execute($savedAgain);
    $restored = app(RestoreSurveyPublishedSchemaAction::class)->execute($published);

    $systemFields = $restored->fields()
        ->whereIn('field_key', SurveyResultContextFields::fieldKeys())
        ->get();

    expect($systemFields)->toHaveCount(4)
        ->and($systemFields->every(fn ($field): bool => $field->is_hidden && $field->is_personalized))->toBeTrue()
        ->and(collect($restored->draft_schema['pages'])->flatMap->elements
            ->whereIn('field_key', SurveyResultContextFields::fieldKeys()))->toHaveCount(4);
});

it('remaps fields when the selected list changes', function () {
    $firstList = resultContextAudienceList();
    $secondList = resultContextAudienceList('CSI', [
        ['key' => 'dealer_name', 'label' => '經銷商', 'type' => 'string'],
        ['key' => 'branch', 'label' => '據點', 'type' => 'string'],
        ['key' => 'plate', 'label' => '車牌', 'type' => 'string'],
        ['key' => 'delivery_date', 'label' => '交車日', 'type' => 'date'],
    ]);
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, resultContextSchema($firstList));
    $schema = resultContextSchema($secondList, [
        'settings' => ['personalization' => ['result_context_columns' => [
            'dealer' => 'dealer_name',
            'location' => 'branch',
            'vehicle_plate' => 'plate',
            'delivery_date' => 'delivery_date',
        ]]],
    ]);

    $remapped = app(SaveSurveyDraftSchemaAction::class)->execute($saved, $schema);

    expect(collect($remapped->draft_schema['pages'])->flatMap->elements
        ->firstWhere('field_key', 'system_context_dealer')['personalized_key'])->toBe('dealer_name')
        ->and($remapped->fields()->whereIn('field_key', SurveyResultContextFields::fieldKeys())->count())->toBe(0);
});

it('snapshots all result context values from the recipient and rejects forged frontend values', function () {
    $list = resultContextAudienceList();
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $published = app(PublishSurveyAction::class)->execute(
        app(SaveSurveyDraftSchemaAction::class)->execute($survey, resultContextSchema($list)),
    );
    $recipient = SurveyRecipient::create([
        'survey_id' => $published->id,
        'name' => '王小明',
        'email' => 'customer@example.com',
        'payload_json' => [
            'dlr' => '甲經銷商',
            'dept' => '台北據點',
            'regono' => 'ABC-1234',
            'timedelivered' => '2026-07-19',
        ],
        'status' => SurveyRecipientStatus::Active,
    ]);
    $token = app(GenerateSurveyTokenAction::class)->execute($published, $recipient, maxSubmissions: 1);
    $resolved = app(ResolveSurveyTokenAction::class)->execute($published, $token->token);
    $payload = new SubmissionPayload(
        visibleAnswers: [
            'score_note' => '很好',
            'system_context_dealer' => '偽造經銷商',
        ],
        tokenContext: $resolved,
    );

    $response = app(SubmitSurveyResponseAction::class)->execute($published->load('fields'), $payload);
    $answers = $response->answers->keyBy(fn ($answer): string => $answer->field->field_key);

    expect($answers->get('system_context_dealer')->answer_text)->toBe('甲經銷商')
        ->and($answers->get('system_context_location')->answer_text)->toBe('台北據點')
        ->and($answers->get('system_context_vehicle_plate')->answer_text)->toBe('ABC-1234')
        ->and($answers->get('system_context_delivery_date')->answer_text)->toBe('2026-07-19');
});

it('removes only reserved fields when personalization is removed', function () {
    $list = resultContextAudienceList();
    $schema = resultContextSchema($list);
    $schema['pages'][0]['elements'][] = [
        'id' => 'manual_hidden',
        'type' => 'short_text',
        'field_key' => 'manual_hidden',
        'label' => '手動欄位',
        'description' => '',
        'required' => false,
        'placeholder' => null,
        'options' => [],
        'settings' => [],
        'is_hidden' => true,
        'personalized_key' => 'dept',
    ];
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $saved = app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);
    $withoutList = $saved->draft_schema;
    $withoutList['settings']['personalization']['audience_list_id'] = null;

    $cleared = app(SaveSurveyDraftSchemaAction::class)->execute($saved, $withoutList);

    $draftFields = collect($cleared->draft_schema['pages'])->flatMap->elements;

    expect($draftFields->whereIn('field_key', SurveyResultContextFields::fieldKeys()))->toHaveCount(0)
        ->and($draftFields->firstWhere('field_key', 'manual_hidden'))->not->toBeNull();
});

it('blocks publishing required categories without a list', function () {
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $survey->update(['draft_schema' => resultContextSchema(null)]);

    expect(fn () => app(PublishSurveyAction::class)->execute($survey->refresh()))
        ->toThrow(SurveyNotAvailableException::class, 'CSI、SSI、IQS 問卷發佈前，請先選擇個性化名單。');
});

it('blocks publishing when list profile does not match the survey category', function () {
    $list = resultContextAudienceList('SSI');
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $survey->update(['draft_schema' => resultContextSchema($list)]);

    expect(fn () => app(PublishSurveyAction::class)->execute($survey->refresh()))
        ->toThrow(SurveyNotAvailableException::class, '個性化名單的資料設定檔必須與問卷分類 CSI 相同。');
});

it('blocks publishing invalid mappings and non-date delivery columns', function (array $mappingOverrides, string $message) {
    $list = resultContextAudienceList('CSI', [
        ['key' => 'dlr', 'label' => '經銷商', 'type' => 'string'],
        ['key' => 'dept', 'label' => '據點', 'type' => 'string'],
        ['key' => 'regono', 'label' => '車牌', 'type' => 'string'],
        ['key' => 'timedelivered', 'label' => '交車日', 'type' => 'string'],
    ]);
    $survey = Survey::create(['title' => '問卷', 'status' => SurveyStatus::Draft]);
    $schema = resultContextSchema($list, [
        'settings' => ['personalization' => ['result_context_columns' => $mappingOverrides]],
    ]);
    $survey->update(['draft_schema' => $schema]);

    expect(fn () => app(PublishSurveyAction::class)->execute($survey->refresh()))
        ->toThrow(SurveyNotAvailableException::class, $message);
})->with([
    'missing dealer mapping' => [
        ['dealer' => null],
        '問卷結果固定欄位「經銷商」尚未對應有效的名單欄位。',
    ],
    'delivery mapping is not a date' => [
        [],
        '問卷結果固定欄位「交車日」必須對應日期類型的名單欄位。',
    ],
]);
