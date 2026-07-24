<?php

use Lalalili\SurveyCore\Actions\ComputeSurveyAnalyticsAction;
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyResponseQualityStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;

function publishedAnalyticsNpsSurvey(string $title = 'NPS analytics'): Survey
{
    return app(PublishSurveyAction::class)->execute(Survey::create([
        'title' => $title,
        'status' => SurveyStatus::Draft,
        'draft_schema' => [
            'id' => 'nps-analytics',
            'title' => $title,
            'status' => 'draft',
            'version' => 1,
            'pages' => [[
                'id' => 'page_1',
                'title' => 'NPS',
                'elements' => [[
                    'id' => 'nps_question',
                    'type' => 'nps',
                    'field_key' => 'nps',
                    'label' => 'NPS',
                    'description' => '',
                    'required' => false,
                    'placeholder' => null,
                    'options' => [],
                    'settings' => [],
                ]],
            ]],
        ],
    ]));
}

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

    $linearScale = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::LinearScale,
        'label' => 'Effort',
        'field_key' => 'effort',
        'sort_order' => 3,
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
    SurveyAnswer::create(['survey_response_id' => $first->id, 'survey_field_id' => $linearScale->id, 'answer_text' => '0']);

    $yesterday = now()->subDay()->toDateString();
    $today = now()->toDateString();

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
        ->and($analytics['daily'])->toBe([
            ['date' => $yesterday, 'started' => 1, 'submitted' => 0],
            ['date' => $today, 'started' => 1, 'submitted' => 2],
        ])
        ->and($analytics['questions'][0]['distribution'])
        ->toBe([
            ['value' => 'basic', 'label' => 'Basic', 'count' => 1],
            ['value' => 'pro', 'label' => 'Pro', 'count' => 1],
        ])
        ->and($analytics['questions'][1]['average'])->toBe(8.0)
        ->and($analytics['questions'][1]['distribution'])
        ->toBe([
            ['value' => '0', 'count' => 0],
            ['value' => '1', 'count' => 0],
            ['value' => '2', 'count' => 0],
            ['value' => '3', 'count' => 0],
            ['value' => '4', 'count' => 0],
            ['value' => '5', 'count' => 0],
            ['value' => '6', 'count' => 0],
            ['value' => '7', 'count' => 1],
            ['value' => '8', 'count' => 0],
            ['value' => '9', 'count' => 1],
            ['value' => '10', 'count' => 0],
        ])
        ->and($analytics['questions'][1]['nps'])->toMatchArray([
            'score' => 50.0,
            'respondents' => 2,
            'promoters' => ['count' => 1, 'percentage' => 50.0],
            'passives' => ['count' => 1, 'percentage' => 50.0],
            'detractors' => ['count' => 0, 'percentage' => 0.0],
            'daily' => [
                ['date' => $today, 'score' => 50.0, 'respondents' => 2],
            ],
        ])
        ->and($analytics['questions'][2]['distribution'])->toBe([
            ['value' => '0', 'count' => 1],
        ]);
});

it('filters totals, daily trend, and question stats by collector', function (): void {
    $survey = Survey::create([
        'title' => 'Analytics by collector',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    $webLink = SurveyCollector::create([
        'survey_id' => $survey->id,
        'type' => 'web_link',
        'name' => 'Web link',
        'slug' => 'web-link',
    ]);
    $emailInvite = SurveyCollector::create([
        'survey_id' => $survey->id,
        'type' => 'email_invite',
        'name' => 'Email invite',
        'slug' => 'email-invite',
    ]);

    $nps = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::Nps,
        'label' => 'NPS',
        'field_key' => 'nps',
        'sort_order' => 1,
    ]);

    foreach ([$webLink, $emailInvite] as $collector) {
        SurveyResponseEvent::create([
            'survey_id' => $survey->id,
            'survey_collector_id' => $collector->id,
            'event' => 'started',
            'occurred_at' => now(),
        ]);
    }

    $webResponse = SurveyResponse::create([
        'survey_id' => $survey->id,
        'survey_collector_id' => $webLink->id,
        'submitted_at' => now(),
        'completion_status' => SurveyResponseCompletionStatus::Complete,
    ]);
    $emailResponse = SurveyResponse::create([
        'survey_id' => $survey->id,
        'survey_collector_id' => $emailInvite->id,
        'submitted_at' => now(),
        'completion_status' => SurveyResponseCompletionStatus::Complete,
    ]);

    SurveyAnswer::create(['survey_response_id' => $webResponse->id, 'survey_field_id' => $nps->id, 'answer_text' => '10']);
    SurveyAnswer::create(['survey_response_id' => $emailResponse->id, 'survey_field_id' => $nps->id, 'answer_text' => '2']);

    $filtered = app(ComputeSurveyAnalyticsAction::class)->execute($survey, $webLink->id);

    expect($filtered['totals'])
        ->toMatchArray(['responses' => 1, 'started' => 1, 'submitted' => 1])
        ->and($filtered['daily'])->toBe([
            ['date' => now()->toDateString(), 'started' => 1, 'submitted' => 1],
        ])
        ->and($filtered['questions'][0]['answered'])->toBe(1)
        ->and($filtered['questions'][0]['average'])->toBe(10.0)
        ->and($filtered['questions'][0]['nps']['score'])->toBe(100.0);

    $emailFiltered = app(ComputeSurveyAnalyticsAction::class)->execute($survey, $emailInvite->id);

    $unfiltered = app(ComputeSurveyAnalyticsAction::class)->execute($survey);

    expect($emailFiltered['questions'][0]['nps']['score'])->toBe(-100.0)
        ->and($unfiltered['totals']['submitted'])->toBe(2)
        ->and($unfiltered['questions'][0]['average'])->toBe(6.0)
        ->and($unfiltered['questions'][0]['nps']['score'])->toBe(0.0);
});

it('computes NPS groups, zero score distribution, and daily trend from valid answers', function (): void {
    $survey = publishedAnalyticsNpsSurvey();
    $nps = $survey->fields()->where('field_key', 'nps')->sole();
    $scoresByDate = [
        [now()->subDay()->startOfDay(), [9, 10]],
        [now()->startOfDay(), [0, 6, 8]],
    ];

    foreach ($scoresByDate as [$submittedAt, $scores]) {
        foreach ($scores as $score) {
            $response = SurveyResponse::create([
                'survey_id' => $survey->id,
                'submitted_at' => $submittedAt,
                'completion_status' => SurveyResponseCompletionStatus::Complete,
            ]);
            SurveyAnswer::create([
                'survey_response_id' => $response->id,
                'survey_field_id' => $nps->id,
                'answer_text' => (string) $score,
            ]);
        }
    }

    $invalidResponse = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'completion_status' => SurveyResponseCompletionStatus::Complete,
    ]);
    SurveyAnswer::create([
        'survey_response_id' => $invalidResponse->id,
        'survey_field_id' => $nps->id,
        'answer_text' => '11',
    ]);

    $question = app(ComputeSurveyAnalyticsAction::class)->execute($survey)['questions'][0];

    expect($question['answered'])->toBe(5)
        ->and($question['average'])->toBe(6.6)
        ->and($question['distribution'][0])->toBe(['value' => '0', 'count' => 1])
        ->and($question['distribution'])->toHaveCount(11)
        ->and($question['nps'])->toMatchArray([
            'score' => 0.0,
            'respondents' => 5,
            'promoters' => ['count' => 2, 'percentage' => 40.0],
            'passives' => ['count' => 1, 'percentage' => 20.0],
            'detractors' => ['count' => 2, 'percentage' => 40.0],
            'daily' => [
                ['date' => now()->subDay()->toDateString(), 'score' => 100.0, 'respondents' => 2],
                ['date' => now()->toDateString(), 'score' => -66.7, 'respondents' => 3],
            ],
        ]);
});

it('returns an empty NPS summary without valid answers', function (): void {
    $survey = publishedAnalyticsNpsSurvey('Empty NPS analytics');
    $question = app(ComputeSurveyAnalyticsAction::class)->execute($survey)['questions'][0];

    expect($question['distribution'])->toHaveCount(11)
        ->and(collect($question['distribution'])->sum('count'))->toBe(0)
        ->and($question['nps'])->toBe([
            'score' => null,
            'respondents' => 0,
            'promoters' => ['count' => 0, 'percentage' => 0.0],
            'passives' => ['count' => 0, 'percentage' => 0.0],
            'detractors' => ['count' => 0, 'percentage' => 0.0],
            'daily' => [],
        ]);
});

it('excludes test, flagged, and quarantined responses from analytics', function (): void {
    $survey = Survey::create([
        'title' => 'Reportable analytics',
        'status' => SurveyStatus::Published,
    ]);
    $field = SurveyField::create([
        'survey_id' => $survey->id,
        'type' => SurveyFieldType::SingleChoice,
        'label' => 'Choice',
        'field_key' => 'choice',
        'options_json' => [
            ['label' => 'Included', 'value' => 'included'],
            ['label' => 'Excluded', 'value' => 'excluded'],
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

    $responses = collect([
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'submitted_at' => now(),
            'quality_status' => SurveyResponseQualityStatus::Accepted,
            'is_test' => false,
        ]),
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'submitted_at' => now(),
            'quality_status' => SurveyResponseQualityStatus::Accepted,
            'is_test' => true,
        ]),
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'submitted_at' => now(),
            'quality_status' => SurveyResponseQualityStatus::Flagged,
            'is_test' => false,
        ]),
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'submitted_at' => now(),
            'quality_status' => SurveyResponseQualityStatus::Quarantined,
            'is_test' => false,
        ]),
    ]);

    foreach ($responses as $index => $response) {
        SurveyAnswer::create([
            'survey_response_id' => $response->id,
            'survey_field_id' => $field->id,
            'answer_text' => $index === 0 ? 'included' : 'excluded',
        ]);
        SurveyAnswer::create([
            'survey_response_id' => $response->id,
            'survey_field_id' => $nps->id,
            'answer_text' => $index === 0 ? '9' : '0',
        ]);
    }

    $analytics = app(ComputeSurveyAnalyticsAction::class)->execute($survey);

    expect($analytics['totals']['submitted'])->toBe(1)
        ->and($analytics['questions'][0]['answered'])->toBe(1)
        ->and($analytics['questions'][0]['distribution'])->toBe([
            ['value' => 'included', 'label' => 'Included', 'count' => 1],
            ['value' => 'excluded', 'label' => 'Excluded', 'count' => 0],
        ])
        ->and($analytics['questions'][1]['nps']['respondents'])->toBe(1)
        ->and($analytics['questions'][1]['nps']['score'])->toBe(100.0)
        ->and($analytics['questions'][1]['distribution'][0])->toBe(['value' => '0', 'count' => 0]);
});

it('builds a per-page drop-off funnel from page_viewed events', function (): void {
    $survey = Survey::create([
        'title' => 'Funnel',
        'status' => SurveyStatus::Published,
        'allow_anonymous' => true,
    ]);

    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'p1', 'title' => '第一頁', 'sort_order' => 1]);
    SurveyPage::create(['survey_id' => $survey->id, 'page_key' => 'p2', 'title' => '第二頁', 'sort_order' => 2]);

    foreach (range(1, 3) as $ignored) {
        SurveyResponseEvent::create(['survey_id' => $survey->id, 'event' => 'started', 'occurred_at' => now()]);
    }
    // 第一頁 3 次瀏覽、第二頁 2 次（1 人流失）。
    foreach (['p1', 'p1', 'p1', 'p2', 'p2'] as $pageKey) {
        SurveyResponseEvent::create(['survey_id' => $survey->id, 'event' => 'page_viewed', 'page_key' => $pageKey, 'occurred_at' => now()]);
    }
    SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'completion_status' => SurveyResponseCompletionStatus::Complete,
    ]);

    $funnel = app(ComputeSurveyAnalyticsAction::class)->execute($survey)['funnel'];

    expect($funnel['steps'])->toBe([
        ['key' => '__started__', 'label' => '開始填寫', 'count' => 3],
        ['key' => 'p1', 'label' => '第一頁', 'count' => 3],
        ['key' => 'p2', 'label' => '第二頁', 'count' => 2],
        ['key' => '__submitted__', 'label' => '送出', 'count' => 1],
    ]);
});
