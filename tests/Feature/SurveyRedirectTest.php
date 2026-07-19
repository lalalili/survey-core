<?php

use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Data\SurveySettings;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

if (! function_exists('redirectSchema')) {
    function redirectSchema(array $redirect): array
    {
        return [
            'id' => 1,
            'title' => 'Redirect Survey',
            'status' => 'draft',
            'version' => 1,
            'settings' => ['redirect' => $redirect],
            'pages' => [[
                'id' => 'page_1',
                'kind' => 'question',
                'title' => 'page_1',
                'elements' => [[
                    'id' => 'q1',
                    'type' => 'short_text',
                    'field_key' => 'f1',
                    'label' => 'F1',
                    'description' => '',
                    'required' => false,
                    'placeholder' => null,
                    'options' => [],
                    'settings' => [],
                ]],
            ]],
        ];
    }
}

it('parses redirect settings with url, mode and delay', function () {
    $settings = SurveySettings::fromArray(['redirect' => ['url' => 'https://example.com/x', 'mode' => 'auto', 'delay_seconds' => 12]]);

    expect($settings->redirectUrl)->toBe('https://example.com/x');
    expect($settings->redirectMode)->toBe('auto');
    expect($settings->redirectDelaySeconds)->toBe(12);
});

it('rejects non-http(s) redirect urls', function () {
    expect(SurveySettings::fromArray(['redirect' => ['url' => 'javascript:alert(1)']])->redirectUrl)->toBeNull();
    expect(SurveySettings::fromArray(['redirect' => ['url' => 'data:text/html,x']])->redirectUrl)->toBeNull();
    expect(SurveySettings::fromArray(['redirect' => ['url' => 'https://ok.test']])->redirectUrl)->toBe('https://ok.test');
});

it('defaults mode to link and clamps delay to 0-30', function () {
    $settings = SurveySettings::fromArray(['redirect' => ['url' => 'https://ok.test', 'mode' => 'weird', 'delay_seconds' => 99]]);

    expect($settings->redirectMode)->toBe('link');
    expect($settings->redirectDelaySeconds)->toBe(30);
});

it('round-trips redirect through toArray', function () {
    $settings = SurveySettings::fromArray(['redirect' => ['url' => 'https://a.test', 'mode' => 'auto', 'delay_seconds' => 5]]);
    $restored = SurveySettings::fromArray($settings->toArray());

    expect($restored->redirectUrl)->toBe('https://a.test');
    expect($restored->redirectMode)->toBe('auto');
    expect($restored->redirectDelaySeconds)->toBe(5);
});

it('injects REDIRECT_CONFIG into the public survey page', function () {
    $survey = Survey::create(['title' => 'R', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    app(SaveSurveyDraftSchemaAction::class)->execute(
        $survey,
        redirectSchema(['url' => 'https://example.com/next', 'mode' => 'auto', 'delay_seconds' => 8]),
    );
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('example.com', false)
        ->assertSee('"mode":"auto"', false)
        ->assertSee('applyThankYouRedirect', false);
});

it('omits REDIRECT_CONFIG url when none is set', function () {
    $survey = Survey::create(['title' => 'NoRedirect', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, redirectSchema([]));
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('var REDIRECT_CONFIG = null', false);
});
