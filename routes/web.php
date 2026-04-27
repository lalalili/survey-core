<?php

use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;

Route::prefix(config('survey-core.route_prefix', 'survey'))
    ->middleware(config('survey-core.route_middleware', ['web']))
    ->group(function () {
        Route::get('/{publicKey}', [PublicSurveyController::class, 'show'])
            ->name('survey.show');

        Route::post('/{publicKey}/submit', [PublicSurveyController::class, 'submit'])
            ->name('survey.submit');

        Route::post('/{publicKey}/upload', [PublicSurveyController::class, 'upload'])
            ->name('survey.upload');
    });
