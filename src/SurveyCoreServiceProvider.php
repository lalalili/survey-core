<?php

namespace Lalalili\SurveyCore;

use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Actions\HydratePersonalizedFieldsAction;
use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Actions\ValidateSurveySubmissionAction;
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Services\Exports\CsvSurveyExportDriver;
use Lalalili\SurveyCore\Services\Exports\SurveyExportManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SurveyCoreServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('survey-core')
            ->hasConfigFile('survey-core')
            ->hasViews()
            ->hasMigrations([
                '2026_04_23_000001_create_surveys_table',
                '2026_04_23_000002_create_survey_fields_table',
                '2026_04_23_000003_create_survey_recipients_table',
                '2026_04_23_000004_create_survey_tokens_table',
                '2026_04_23_000005_create_survey_responses_table',
                '2026_04_23_000006_create_survey_answers_table',
            ])
            ->runsMigrations()
            ->hasRoutes(['web']);
    }

    public function registeringPackage(): void
    {
        // Personalization resolver — swappable via config
        $this->app->bind(PersonalizationResolver::class, function ($app) {
            return $app->make(config('survey-core.personalization.resolver'));
        });

        // Export manager with built-in CSV driver
        $this->app->singleton(SurveyExportManager::class, function () {
            $manager = new SurveyExportManager;
            $manager->extend('csv', fn () => new CsvSurveyExportDriver);

            return $manager;
        });

        // Actions — explicit bindings so constructor injection resolves cleanly
        $this->app->bind(HydratePersonalizedFieldsAction::class, function ($app) {
            return new HydratePersonalizedFieldsAction(
                $app->make(PersonalizationResolver::class),
            );
        });

        $this->app->bind(SubmitSurveyResponseAction::class, function ($app) {
            return new SubmitSurveyResponseAction(
                $app->make(ResolveSurveyTokenAction::class),
                $app->make(HydratePersonalizedFieldsAction::class),
                $app->make(ValidateSurveySubmissionAction::class),
            );
        });

        $this->app->bind(ExportSurveyResponsesAction::class, function ($app) {
            return new ExportSurveyResponsesAction(
                $app->make(SurveyExportManager::class),
            );
        });
    }
}
