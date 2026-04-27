<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

$phase3TestCase = class_exists(Tests\TestCase::class)
    ? Tests\TestCase::class
    : 'Lalalili\\SurveyCore\\Tests\\TestCase';

uses($phase3TestCase, RefreshDatabase::class);

beforeEach(function () use ($phase3TestCase): void {
    if ($phase3TestCase === Tests\TestCase::class) {
        $this->app->register(Lalalili\SurveyCore\SurveyCoreServiceProvider::class);
        $this->artisan('migrate', [
            '--path' => base_path('packages/survey-core/database/migrations'),
            '--realpath' => true,
        ])->run();
    }

    Route::get('/survey/{publicKey}', [Lalalili\SurveyCore\Http\Controllers\PublicSurveyController::class, 'show'])->name('survey.show');
    Route::post('/survey/{publicKey}/submit', [Lalalili\SurveyCore\Http\Controllers\PublicSurveyController::class, 'submit'])->name('survey.submit');
    Route::post('/survey/{publicKey}/upload', [Lalalili\SurveyCore\Http\Controllers\PublicSurveyController::class, 'upload'])->name('survey.upload');
    Route::getRoutes()->refreshNameLookups();
});
