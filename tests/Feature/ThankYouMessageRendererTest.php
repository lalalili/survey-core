<?php

use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Support\ThankYouMessageRenderer;

function rendererSurvey(array $attributes = []): Survey
{
    return Survey::create(array_merge([
        'title' => 'Renderer Survey',
        'status' => SurveyStatus::Published,
        'public_key' => 'rend-'.bin2hex(random_bytes(6)),
    ], $attributes));
}

it('interpolates calculation tokens into the fallback success message', function (): void {
    $survey = rendererSurvey(['submit_success_message' => '您的分數為 {{ calc.score }} 分']);

    $message = (new ThankYouMessageRenderer)->messageFor($survey, ['score' => 88]);

    expect($message)->toBe('您的分數為 88 分');
});

it('falls back to the default message when none is set', function (): void {
    $survey = rendererSurvey(['submit_success_message' => null]);

    expect((new ThankYouMessageRenderer)->messageFor($survey, []))->toBe('感謝您的填寫！');
});

it('returns null thank-you page when the survey has no thank_you pages', function (): void {
    $survey = rendererSurvey();

    expect((new ThankYouMessageRenderer)->pageFor($survey, []))->toBeNull();
});
