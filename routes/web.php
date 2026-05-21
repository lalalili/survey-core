<?php

use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;

Route::prefix(config('survey-core.route_prefix', 'survey'))
    ->middleware(config('survey-core.route_middleware', ['web']))
    ->group(function () {
        Route::get('/{publicKey}', [PublicSurveyController::class, 'show'])
            ->name('survey.show');

        Route::post('/{publicKey}/submit', [PublicSurveyController::class, 'submit'])
            ->middleware('throttle:survey-core-submissions')
            ->name('survey.submit');

        Route::post('/{publicKey}/upload', [PublicSurveyController::class, 'upload'])
            ->middleware('throttle:survey-core-submissions')
            ->name('survey.upload');

        Route::post('/{publicKey}/events', [PublicSurveyController::class, 'event'])
            ->middleware('throttle:survey-core-submissions')
            ->name('survey.events');

        Route::post('/{publicKey}/password', [PublicSurveyController::class, 'unlock'])
            ->middleware('throttle:survey-core-submissions')
            ->name('survey.password');
    });

Route::prefix(config('survey-core.collectors.route_prefix', 's'))
    ->middleware(config('survey-core.route_middleware', ['web']))
    ->group(function () {
        Route::get('/{collectorSlug}', [PublicSurveyController::class, 'showCollector'])
            ->name('survey.collector.show');

        Route::post('/{collectorSlug}/password', [PublicSurveyController::class, 'unlockCollector'])
            ->middleware('throttle:survey-core-submissions')
            ->name('survey.collector.password');
    });
