<?php

use Illuminate\Support\Facades\Route;
use Lalalili\SurveyCore\Http\Controllers\PublicSurveyController;

beforeEach(function (): void {
    Route::get('/survey/{publicKey}', [PublicSurveyController::class, 'show'])->name('survey.show');
    Route::post('/survey/{publicKey}/submit', [PublicSurveyController::class, 'submit'])->name('survey.submit');
    Route::post('/survey/{publicKey}/upload', [PublicSurveyController::class, 'upload'])->name('survey.upload');
    Route::getRoutes()->refreshNameLookups();
});
