<?php

use Illuminate\Support\Facades\Event;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

function makeSurvey(SurveyStatus $status = SurveyStatus::Published): Survey
{
    $survey = Survey::create(['title' => 'Test', 'status' => $status]);

    SurveyField::create([
        'survey_id'   => $survey->id,
        'type'        => SurveyFieldType::ShortText,
        'label'       => 'Name',
        'field_key'   => 'name',
        'is_required' => true,
        'sort_order'  => 1,
    ]);

    SurveyField::create([
        'survey_id'    => $survey->id,
        'type'         => SurveyFieldType::SingleChoice,
        'label'        => 'Color',
        'field_key'    => 'color',
        'is_required'  => false,
        'options_json' => [['value' => 'red'], ['value' => 'blue']],
        'sort_order'   => 2,
    ]);

    SurveyField::create([
        'survey_id'    => $survey->id,
        'type'         => SurveyFieldType::MultipleChoice,
        'label'        => 'Tags',
        'field_key'    => 'tags',
        'is_required'  => false,
        'options_json' => [['value' => 'a'], ['value' => 'b'], ['value' => 'c']],
        'sort_order'   => 3,
    ]);

    return $survey->load('fields');
}

beforeEach(function () {
    Event::fake();
});

// ── Status gate ──────────────────────────────────────────────────────────────

it('allows submission to a published survey', function () {
    $survey = makeSurvey(SurveyStatus::Published);

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice']),
    );

    expect($response->id)->toBeInt()
        ->and($response->survey_id)->toBe($survey->id);

    Event::assertDispatched(SurveySubmitted::class);
});

it('rejects submission to a draft survey', function () {
    $survey = makeSurvey(SurveyStatus::Draft);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice']),
    );
})->throws(SurveyNotAvailableException::class);

it('rejects submission to a closed survey', function () {
    $survey = makeSurvey(SurveyStatus::Closed);

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice']),
    );
})->throws(SurveyNotAvailableException::class);

it('rejects submission outside the time window', function () {
    $survey = Survey::create([
        'title'   => 'Timed',
        'status'  => SurveyStatus::Published,
        'ends_at' => now()->subDay(),
    ]);
    $survey->load('fields');

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload([]),
    );
})->throws(SurveyNotAvailableException::class);

// ── Required field validation ─────────────────────────────────────────────────

it('rejects submission when a required field is missing', function () {
    $survey = makeSurvey();

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload([]),   // 'name' is required but absent
    );
})->throws(SurveyValidationException::class);

// ── Choice option validation ───────────────────────────────────────────────────

it('rejects an invalid single_choice value', function () {
    $survey = makeSurvey();

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice', 'color' => 'green']),
    );
})->throws(SurveyValidationException::class);

it('rejects invalid multiple_choice values', function () {
    $survey = makeSurvey();

    app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice', 'tags' => ['a', 'INVALID']]),
    );
})->throws(SurveyValidationException::class);

it('accepts valid multiple_choice values', function () {
    $survey = makeSurvey();

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice', 'tags' => ['a', 'b']]),
    );

    expect($response->id)->toBeInt();
});

// ── Answer persistence ────────────────────────────────────────────────────────

it('persists all answers with correct field associations', function () {
    $survey = makeSurvey();

    $response = app(SubmitSurveyResponseAction::class)->execute(
        $survey,
        new SubmissionPayload(['name' => 'Alice', 'color' => 'red', 'tags' => ['a', 'c']]),
    );

    $answers = $response->answers->load('field')->keyBy(fn ($a) => $a->field->field_key);

    expect($answers->get('name')->answer_text)->toBe('Alice')
        ->and($answers->get('color')->answer_text)->toBe('red')
        ->and($answers->get('tags')->answer_json)->toBe(['a', 'c']);
});

// ── field_key stability ───────────────────────────────────────────────────────

it('field_key remains stable when sort_order changes', function () {
    $survey = Survey::create(['title' => 'Stable', 'status' => SurveyStatus::Draft]);

    $field = SurveyField::create([
        'survey_id'  => $survey->id,
        'type'       => SurveyFieldType::ShortText,
        'label'      => 'Email Address',
        'sort_order' => 1,
    ]);

    $originalKey = $field->field_key;

    $field->update(['sort_order' => 99]);

    expect($field->fresh()->field_key)->toBe($originalKey);
});
