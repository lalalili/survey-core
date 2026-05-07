<?php

use Lalalili\SurveyCore\Actions\ComputeSurveyAnalyticsAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;

it('computes totals, collector performance, daily trend, and question distributions', function (): void {
    $survey = Survey::create([
        'title' => 'Analytics',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    $collector = SurveyCollector::create([
        'survey_id' => $survey->id,
        'type' => 'web_link',
        'name' => 'Landing page',
        'slug' => 'landing-page',
    ]);

    $choice = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Plan',
        'field_key' => 'plan',
        'options_json' => [
            ['label' => 'Basic', 'value' => 'basic'],
            ['label' => 'Pro', 'value' => 'pro'],
        ],
        'sort_order' => 1,
    ]);

    $nps = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Nps,
        'label' => 'NPS',
        'field_key' => 'nps',
        'sort_order' => 2,
    ]);

    SurveyResponseEvent::create([
        'survey_id' => $survey->id,
        'survey_collector_id' => $collector->id,
        'event' => 'started',
        'occurred_at' => now()->subDay(),
    ]);
    SurveyResponseEvent::create([
        'survey_id' => $survey->id,
        'survey_collector_id' => $collector->id,
        'event' => 'started',
        'occurred_at' => now(),
    ]);

    $first = SurveyResponse::create([
        'survey_id' => $survey->id,
        'survey_collector_id' => $collector->id,
        'submitted_at' => now(),
        'completion_status' => SurveyResponseCompletionStatus::Complete,
    ]);
    $second = SurveyResponse::create([
        'survey_id' => $survey->id,
        'survey_collector_id' => $collector->id,
        'submitted_at' => now(),
        'completion_status' => SurveyResponseCompletionStatus::Complete,
    ]);

    SurveyAnswer::create(['survey_response_id' => $first->id, 'survey_field_id' => $choice->id, 'answer_text' => 'basic']);
    SurveyAnswer::create(['survey_response_id' => $second->id, 'survey_field_id' => $choice->id, 'answer_text' => 'pro']);
    SurveyAnswer::create(['survey_response_id' => $first->id, 'survey_field_id' => $nps->id, 'answer_text' => '9']);
    SurveyAnswer::create(['survey_response_id' => $second->id, 'survey_field_id' => $nps->id, 'answer_text' => '7']);

    $analytics = app(ComputeSurveyAnalyticsAction::class)->execute($survey);

    expect($analytics['totals'])
        ->toMatchArray([
            'responses' => 2,
            'started' => 2,
            'submitted' => 2,
            'completion_rate' => 100.0,
        ])
        ->and($analytics['collectors'][0])
        ->toMatchArray([
            'collector_id' => $collector->id,
            'started' => 2,
            'submitted' => 2,
            'completion_rate' => 100.0,
        ])
        ->and($analytics['daily'])->toHaveCount(2)
        ->and($analytics['questions'][0]['distribution'])
        ->toBe([
            ['value' => 'basic', 'label' => 'Basic', 'count' => 1],
            ['value' => 'pro', 'label' => 'Pro', 'count' => 1],
        ])
        ->and($analytics['questions'][1]['average'])->toBe(8.0)
        ->and($analytics['questions'][1]['distribution'])
        ->toBe([
            ['value' => '7', 'count' => 1],
            ['value' => '9', 'count' => 1],
        ]);
});
