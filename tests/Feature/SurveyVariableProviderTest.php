<?php

use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Integrations\EmailCampaign\SurveyVariableProvider;
use Lalalili\SurveyCore\Models\AudienceList;
use Lalalili\SurveyCore\Models\AudienceListRow;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Tests\TestCase;

$surveyVariableProviderTestCase = class_exists(TestCase::class)
    ? TestCase::class
    : Lalalili\SurveyCore\Tests\TestCase::class;

if ($surveyVariableProviderTestCase === TestCase::class) {
    uses($surveyVariableProviderTestCase);
}

beforeEach(function () use ($surveyVariableProviderTestCase): void {
    if ($surveyVariableProviderTestCase === TestCase::class) {
        $this->artisan('migrate', ['--path' => 'packages/survey-core/database/migrations'])->run();
    }
});

function makeEmailCampaignObject(?int $surveyId): object
{
    return (object) ['survey_id' => $surveyId];
}

function makeEmailRecipientObject(?int $audienceListRowId = null): object
{
    return (object) [
        'audience_list_row_id' => $audienceListRowId,
        'email' => 'owner@example.com',
        'user_name' => '車主',
        'external_id' => $audienceListRowId ? (string) $audienceListRowId : null,
    ];
}

it('provides a personalized survey URL for an audience list recipient', function () {
    $audienceList = AudienceList::create([
        'name' => '車主名單',
        'columns_json' => ['email', 'plate'],
    ]);
    $audienceRow = AudienceListRow::create([
        'audience_list_id' => $audienceList->id,
        'data_json' => ['email' => 'owner@example.com', 'plate' => 'ABC-1234'],
        'status' => 'active',
    ]);
    $survey = Survey::create([
        'title' => '車主問卷',
        'status' => SurveyStatus::Published,
        'settings_json' => [
            'personalization' => [
                'audience_list_id' => $audienceList->id,
            ],
        ],
    ]);

    $provider = new SurveyVariableProvider(app(GenerateSurveyTokenAction::class));
    $vars = $provider->variablesFor(makeEmailCampaignObject($survey->id), makeEmailRecipientObject($audienceRow->id));

    $surveyRecipient = SurveyRecipient::where('survey_id', $survey->id)
        ->where('audience_list_row_id', $audienceRow->id)
        ->first();

    expect($vars['survey_title'])->toBe('車主問卷')
        ->and($vars['survey_public_key'])->toBe($survey->public_key)
        ->and($vars['survey_url'])->toContain(route('survey.show', $survey->public_key))
        ->and($vars['survey_url'])->toContain('?t=')
        ->and($surveyRecipient)->not->toBeNull()
        ->and($surveyRecipient->tokens()->count())->toBe(1);
});

it('reuses an existing usable survey token for the same recipient', function () {
    $audienceList = AudienceList::create(['name' => '車主名單', 'columns_json' => ['email']]);
    $audienceRow = AudienceListRow::create([
        'audience_list_id' => $audienceList->id,
        'data_json' => ['email' => 'owner@example.com'],
        'status' => 'active',
    ]);
    $survey = Survey::create([
        'title' => '車主問卷',
        'status' => SurveyStatus::Published,
    ]);
    $provider = new SurveyVariableProvider(app(GenerateSurveyTokenAction::class));
    $campaign = makeEmailCampaignObject($survey->id);
    $recipient = makeEmailRecipientObject($audienceRow->id);

    $first = $provider->variablesFor($campaign, $recipient);
    $second = $provider->variablesFor($campaign, $recipient);

    $surveyRecipient = SurveyRecipient::where('survey_id', $survey->id)
        ->where('audience_list_row_id', $audienceRow->id)
        ->firstOrFail();

    expect($second['survey_url'])->toBe($first['survey_url'])
        ->and($surveyRecipient->tokens()->count())->toBe(1);
});

it('returns no survey variables when campaign has no survey', function () {
    $provider = new SurveyVariableProvider(app(GenerateSurveyTokenAction::class));

    expect($provider->variablesFor(makeEmailCampaignObject(null), makeEmailRecipientObject()))->toBe([]);
});

it('fails rendering when the selected survey no longer exists', function () {
    $provider = new SurveyVariableProvider(app(GenerateSurveyTokenAction::class));

    expect(fn () => $provider->variablesFor(makeEmailCampaignObject(999), makeEmailRecipientObject()))
        ->toThrow(RuntimeException::class, 'Survey [999] was not found.');
});

it('fails rendering when the selected survey is not accepting submissions', function () {
    $survey = Survey::create([
        'title' => '草稿問卷',
        'status' => SurveyStatus::Draft,
    ]);
    $provider = new SurveyVariableProvider(app(GenerateSurveyTokenAction::class));

    expect(fn () => $provider->variablesFor(makeEmailCampaignObject($survey->id), makeEmailRecipientObject()))
        ->toThrow(RuntimeException::class, "Survey [{$survey->id}] is not accepting submissions.");
});
