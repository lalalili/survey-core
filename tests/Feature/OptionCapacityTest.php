<?php

use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;

require __DIR__.'/Phase3TestSupport.php';

function phase3CapacitySurvey(?int $capacity = 3): array
{
    $survey = Survey::create(['title' => 'Capacity', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Session',
        'field_key' => 'session',
        'is_required' => true,
        'options_json' => [
            ['id' => 'morning', 'label' => '早場', 'value' => 'morning', 'capacity' => $capacity],
            ['id' => 'night', 'label' => '晚場', 'value' => 'night'],
        ],
        'sort_order' => 1,
    ]);

    return [$survey->load('fields'), $field];
}

it('rejects a selected option after capacity is reached', function () {
    [$survey, $field] = phase3CapacitySurvey(3);

    foreach (range(1, 3) as $index) {
        $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
        SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_field_id' => $field->id, 'answer_text' => 'morning']);
    }

    app(SubmitSurveyResponseAction::class)->execute($survey, new SubmissionPayload(['session' => 'morning']));
})->throws(SurveyValidationException::class);

it('allows unlimited capacity when option capacity is null', function () {
    [$survey, $field] = phase3CapacitySurvey(null);

    foreach (range(1, 5) as $index) {
        $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
        SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_field_id' => $field->id, 'answer_text' => 'morning']);
    }

    $response = app(SubmitSurveyResponseAction::class)->execute($survey, new SubmissionPayload(['session' => 'morning']));

    expect($response->id)->toBeInt();
});

it('renders full options as disabled', function () {
    [$survey, $field] = phase3CapacitySurvey(1);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_field_id' => $field->id, 'answer_text' => 'morning']);

    $this->get("/survey/{$survey->public_key}")
        ->assertSuccessful()
        ->assertSee('disabled', false)
        ->assertSee('早場（已額滿）');
});

it('keeps non-full options enabled', function () {
    [$survey] = phase3CapacitySurvey(1);

    $html = $this->get("/survey/{$survey->public_key}")->getContent();

    expect($html)->toContain('value="night"')
        ->and($html)->not->toContain('晚場（已額滿）');
});

it('counts array answers for multiple choice capacity', function () {
    $survey = Survey::create(['title' => 'Multi Capacity', 'status' => SurveyStatus::Published, 'allow_anonymous' => true]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::MultipleChoice,
        'label' => 'Choices',
        'field_key' => 'choices',
        'is_required' => false,
        'options_json' => [['label' => 'A', 'value' => 'a', 'capacity' => 1]],
        'sort_order' => 1,
    ]);
    $response = SurveyResponse::create(['survey_id' => $survey->id, 'submitted_at' => now(), 'completion_status' => 'complete']);
    SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_field_id' => $field->id, 'answer_json' => ['a']]);

    app(SubmitSurveyResponseAction::class)->execute($survey->load('fields'), new SubmissionPayload(['choices' => ['a']]));
})->throws(SurveyValidationException::class);
