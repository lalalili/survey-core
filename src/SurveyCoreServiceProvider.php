<?php

namespace Lalalili\SurveyCore;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Lalalili\SurveyCore\Actions\CalculateSurveyResponseAction;
use Lalalili\SurveyCore\Actions\EvaluateResponseQualityAction;
use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Actions\HydratePersonalizedFieldsAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Actions\ValidateSurveySubmissionAction;
use Lalalili\SurveyCore\Console\Commands\SurveyScheduleCommand;
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Integrations\EmailCampaign\SurveyVariableProvider;
use Lalalili\SurveyCore\Listeners\DispatchSurveySubmittedWebhook;
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
                '2026_04_23_000007_add_branching_and_pages_to_survey_fields_table',
                '2026_04_24_000008_add_builder_schema_to_surveys_table',
                '2026_04_26_000009_create_survey_pages_table',
                '2026_04_26_000010_replace_page_int_with_page_id_on_survey_fields_table',
                '2026_04_27_000001_add_kind_to_survey_pages_table',
                '2026_04_27_000002_create_survey_themes_table',
                '2026_04_27_000003_add_settings_and_theme_to_surveys_table',
                '2026_04_27_000004_create_survey_calculations_table',
                '2026_04_27_000005_add_calculations_to_survey_responses_table',
                '2026_04_27_000006_add_phase3_controls_to_surveys_table',
                '2026_04_27_000007_add_quality_to_survey_responses_table',
                '2026_04_27_000008_add_notes_to_survey_responses_table',
                '2026_04_27_000009_create_survey_tags_tables',
                '2026_04_28_000001_add_settings_to_survey_fields_table',
                '2026_04_29_000001_create_audience_lists_table',
                '2026_04_29_000002_create_audience_list_rows_table',
                '2026_04_29_000003_add_audience_list_row_to_survey_recipients_table',
            ])
            ->runsMigrations()
            ->hasRoutes(['web']);
    }

    public function bootingPackage(): void
    {
        Event::listen(SurveySubmitted::class, DispatchSurveySubmittedWebhook::class);

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/survey-core'),
        ], 'survey-core-assets');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/survey-core'),
        ], 'survey-core-views');

        $variableProviderContract = 'Lalalili\\EmailCampaign\\Contracts\\VariableProvider';
        $variableProviderRegistry = 'Lalalili\\EmailCampaign\\Support\\VariableProviderRegistry';

        if (interface_exists($variableProviderContract) && class_exists($variableProviderRegistry)) {
            $registry = $this->app->make($variableProviderRegistry);

            if (is_object($registry) && method_exists($registry, 'register')) {
                $registry->register(SurveyVariableProvider::class);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                SurveyScheduleCommand::class,
            ]);

            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command(SurveyScheduleCommand::class)
                    ->everyMinute()
                    ->withoutOverlapping();
            });
        }
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
                $app->make(HydratePersonalizedFieldsAction::class),
                $app->make(ValidateSurveySubmissionAction::class),
                $app->make(CalculateSurveyResponseAction::class),
                $app->make(EvaluateResponseQualityAction::class),
            );
        });

        $this->app->bind(ExportSurveyResponsesAction::class, function ($app) {
            return new ExportSurveyResponsesAction(
                $app->make(SurveyExportManager::class),
            );
        });
    }
}
