<?php

use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;

/**
 * Characterization：pin PublicSurveyController::availabilityView 於 GET 公開頁時
 * 各狀態選用的 view。作為 renderSurvey 重構（抽 ViewModel）的回歸防護。
 */
beforeEach(function (): void {
    Route::get('/survey/{publicKey}', [PublicSurveyController::class, 'show'])->name('survey.show');
    Route::getRoutes()->refreshNameLookups();
});

function availabilitySurvey(array $attributes = []): Survey
{
    return Survey::create(array_merge([
        'title' => 'Availability Survey',
        'status' => SurveyStatus::Published,
        'public_key' => 'avail-'.bin2hex(random_bytes(6)),
    ], $attributes));
}

it('renders the closed view for a non-published (draft) survey', function (): void {
    $survey = availabilitySurvey(['status' => SurveyStatus::Draft]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertOk()
        ->assertViewIs('survey-core::survey.closed');
});

it('renders the not_started view before the survey start time', function (): void {
    $survey = availabilitySurvey(['starts_at' => now()->addDay()]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertOk()
        ->assertViewIs('survey-core::survey.not_started');
});

it('renders the closed view after the survey end time', function (): void {
    $survey = availabilitySurvey(['ends_at' => now()->subDay()]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertOk()
        ->assertViewIs('survey-core::survey.closed');
});

it('renders the quota_full view once max responses is reached', function (): void {
    $survey = availabilitySurvey(['max_responses' => 1]);

    SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
    ]);

    $this->get(route('survey.show', $survey->public_key))
        ->assertOk()
        ->assertViewIs('survey-core::survey.quota_full');
});
