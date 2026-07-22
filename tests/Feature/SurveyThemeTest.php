<?php

use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\SaveSurveyDraftSchemaAction;
use Lalalili\SurveyCore\Database\Seeders\SurveyThemeSeeder;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyTheme;

if (! function_exists('pageKindSchema')) {
    function pageKindSchema(array $pages): array
    {
        return [
            'id' => 1,
            'title' => 'Kind Survey',
            'status' => 'draft',
            'version' => 1,
            'pages' => $pages,
        ];
    }
}

if (! function_exists('kindQuestionPage')) {
    function kindQuestionPage(string $id, string $kind = 'question', bool $required = false): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'title' => $id,
            'elements' => [[
                'id' => 'q_'.$id,
                'type' => 'short_text',
                'field_key' => 'field_'.$id,
                'label' => 'Field '.$id,
                'description' => '',
                'required' => $required,
                'placeholder' => null,
                'options' => [],
                'settings' => [],
            ]],
        ];
    }
}

it('merges theme overrides over tokens', function () {
    $theme = SurveyTheme::create([
        'name' => 'Test',
        'tokens_json' => ['primary' => '#111111', 'accent' => '#222222'],
        'is_system' => true,
    ]);

    expect($theme->resolvedTokens(['primary' => '#ffffff']))->toMatchArray([
        'primary' => '#ffffff',
        'accent' => '#222222',
    ]);
});

it('uses default CSS variables when no theme is set', function () {
    $survey = Survey::create(['title' => 'Default Theme', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, pageKindSchema([kindQuestionPage('page_1')]));
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('--survey-primary: #6366f1', false);
});

it('renders CSS variables from the selected theme', function () {
    $theme = SurveyTheme::create(['name' => 'Theme', 'tokens_json' => ['primary' => '#123456'], 'is_system' => true]);
    $survey = Survey::create(['title' => 'Themed', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    $schema = pageKindSchema([kindQuestionPage('page_1')]);
    $schema['theme_id'] = $theme->id;
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('--survey-primary: #123456', false);
});

it('applies theme overrides to CSS variables', function () {
    $theme = SurveyTheme::create(['name' => 'Theme', 'tokens_json' => ['primary' => '#123456'], 'is_system' => true]);
    $survey = Survey::create(['title' => 'Override', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    $schema = pageKindSchema([kindQuestionPage('page_1')]);
    $schema['theme_id'] = $theme->id;
    $schema['theme_overrides'] = ['primary' => '#abcdef'];
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('--survey-primary: #abcdef', false);
});

it('seeds five system themes', function () {
    $this->seed(SurveyThemeSeeder::class);

    expect(SurveyTheme::query()->where('is_system', true)->count())->toBe(5);
});

it('renders the accent CSS variable from theme overrides', function () {
    $theme = SurveyTheme::create(['name' => 'Theme', 'tokens_json' => ['accent' => '#aabbcc'], 'is_system' => true]);
    $survey = Survey::create(['title' => 'Accent', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    $schema = pageKindSchema([kindQuestionPage('page_1')]);
    $schema['theme_id'] = $theme->id;
    $schema['theme_overrides'] = ['accent' => '#ddeeff'];
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, $schema);
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('--survey-accent: #ddeeff', false);
});

it('applies the accent token to secondary actions', function () {
    $survey = Survey::create(['title' => 'Accent Buttons', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, pageKindSchema([kindQuestionPage('page_1')]));
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('survey-themed-accent-outline', false);
});

it('applies the accent token to filled rating stars', function () {
    $survey = Survey::create(['title' => 'Accent Stars', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    app(SaveSurveyDraftSchemaAction::class)->execute($survey, pageKindSchema([kindQuestionPage('page_1')]));
    app(PublishSurveyAction::class)->execute($survey);

    $this->get(route('survey.show', $survey->public_key))
        ->assertSuccessful()
        ->assertSee('.survey-rating-star-label.filled .survey-rating-star-icon {'.PHP_EOL.'            color: var(--survey-accent);', false);
});
