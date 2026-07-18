<?php

use Lalalili\SurveyCore\Actions\ComputeSurveyAnalyticsAction;
use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Enums\SurveyResponseCompletionStatus;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyPage;
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
            ['value' => '7', 'count' => 1],
            ['value' => '9', 'count' => 1],
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
        ->and($filtered['questions'][0]['average'])->toBe(10.0);

    $unfiltered = app(ComputeSurveyAnalyticsAction::class)->execute($survey);

    expect($unfiltered['totals']['submitted'])->toBe(2)
        ->and($unfiltered['questions'][0]['average'])->toBe(6.0);
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
