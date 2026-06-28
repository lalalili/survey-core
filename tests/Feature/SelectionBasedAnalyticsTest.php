<?php

use Lalalili\SurveyCore\Actions\ComputeSurveyAnalyticsAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

it('builds a selection_based distribution from the source field options', function (): void {
    $survey = Survey::create(['title' => 'SB Analytics', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);

    SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::MultipleChoice,
        'label' => '去過哪些城市',
        'field_key' => 'visited',
        'options_json' => [
            ['label' => '台北', 'value' => 'taipei'],
            ['label' => '台中', 'value' => 'taichung'],
            ['label' => '高雄', 'value' => 'kaohsiung'],
        ],
        'sort_order' => 1,
    ]);

    $selection = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SelectionBased,
        'label' => '最喜歡哪些',
        'field_key' => 'favorite',
        'settings_json' => ['source_field_key' => 'visited'],
        'sort_order' => 2,
    ]);

    foreach ([['taipei', 'taichung'], ['taipei'], ['kaohsiung']] as $picked) {
        $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
        SurveyAnswer::create([
            'survey_response_id' => $response->id,
            'survey_field_id' => $selection->id,
            'answer_json' => $picked,
        ]);
    }

    $analytics = app(ComputeSurveyAnalyticsAction::class)->execute($survey->load('fields'));

    $question = collect($analytics['questions'])->firstWhere('field_key', 'favorite');

    expect($question)->not->toBeNull()
        ->and($question['source_field_key'])->toBe('visited');

    $byValue = collect($question['distribution'])->keyBy('value');

    expect($byValue['taipei']['label'])->toBe('台北')
        ->and($byValue['taipei']['count'])->toBe(2)
        ->and($byValue['taichung']['count'])->toBe(1)
        ->and($byValue['kaohsiung']['count'])->toBe(1);
});
