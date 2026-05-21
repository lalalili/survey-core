<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;
use Lalalili\SurveyCore\SurveyCoreServiceProvider;
use Tests\TestCase;

$phase3TestCase = class_exists(TestCase::class)
    ? TestCase::class
    : 'Lalalili\\SurveyCore\\Tests\\TestCase';

uses($phase3TestCase, RefreshDatabase::class);

beforeEach(function () use ($phase3TestCase): void {
    if ($phase3TestCase === TestCase::class) {
        $this->app->register(SurveyCoreServiceProvider::class);
        $this->artisan('migrate', [
            '--path' => base_path('packages/survey-core/database/migrations'),
            '--realpath' => true,
        ])->run();
    }

    Route::get('/survey/{publicKey}', [PublicSurveyController::class, 'show'])->name('survey.show');
    Route::post('/survey/{publicKey}/submit', [PublicSurveyController::class, 'submit'])->name('survey.submit');
    Route::post('/survey/{publicKey}/upload', [PublicSurveyController::class, 'upload'])->name('survey.upload');
    Route::getRoutes()->refreshNameLookups();
});
