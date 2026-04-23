<?php

use Lalalili\SurveyCore\Enums\SurveyStatus;

it('only published status accepts submissions', function () {
    expect(SurveyStatus::Published->isAcceptingSubmissions())->toBeTrue();
    expect(SurveyStatus::Draft->isAcceptingSubmissions())->toBeFalse();
    expect(SurveyStatus::Closed->isAcceptingSubmissions())->toBeFalse();
    expect(SurveyStatus::Archived->isAcceptingSubmissions())->toBeFalse();
});

it('only published status is publicly visible', function () {
    expect(SurveyStatus::Published->isPubliclyVisible())->toBeTrue();
    expect(SurveyStatus::Draft->isPubliclyVisible())->toBeFalse();
    expect(SurveyStatus::Closed->isPubliclyVisible())->toBeFalse();
    expect(SurveyStatus::Archived->isPubliclyVisible())->toBeFalse();
});
