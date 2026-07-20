<?php

namespace Lalalili\SurveyCore;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Lalalili\SurveyCore\Actions\CalculateSurveyResponseAction;
use Lalalili\SurveyCore\Actions\EvaluateResponseQualityAction;
use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Actions\HydratePersonalizedFieldsAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Actions\ValidateSurveySubmissionAction;
use Lalalili\SurveyCore\Console\Commands\CheckTurnstileConfigCommand;
use Lalalili\SurveyCore\Console\Commands\PrunePartialDraftsCommand;
use Lalalili\SurveyCore\Console\Commands\RunTriggerRulesCommand;
use Lalalili\SurveyCore\Console\Commands\SurveyScheduleCommand;
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Events\SurveyTokenResolved;
use Lalalili\SurveyCore\Integrations\EmailCampaign\SurveyVariableProvider;
use Lalalili\SurveyCore\Listeners\DispatchSurveySubmittedWebhook;
use Lalalili\SurveyCore\Listeners\EvaluateSurveyTriggersListener;
use Lalalili\SurveyCore\Listeners\MarkTokenViewed;
use Lalalili\SurveyCore\Listeners\SendSurveyResponseNotification;
use Lalalili\SurveyCore\Services\Exports\CsvSurveyExportDriver;
use Lalalili\SurveyCore\Services\Exports\SurveyExportManager;
use Lalalili\SurveyCore\Services\Exports\XlsxSurveyExportDriver;
use Lalalili\SurveyCore\Support\EmailCampaignIntegration;
use Lalalili\SurveyCore\Support\SurveyFileUploadToken;
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
                '2026_04_29_000003_add_audience_list_row_to_survey_recipients_table',
                '2026_05_08_000001_create_survey_collectors_table',
                '2026_05_08_000002_add_collector_to_survey_responses_table',
                '2026_05_08_000003_create_survey_response_events_table',
                '2026_05_08_000004_create_survey_response_consents_table',
                '2026_05_18_000001_add_viewed_at_to_survey_tokens_table',
                '2026_05_23_000001_create_survey_trigger_rules_table',
                '2026_05_23_000002_create_survey_trigger_dispatches_table',
                '2026_05_23_000003_create_survey_trigger_allowed_hosts_table',
                '2026_05_30_000001_create_survey_trigger_action_presets_table',
                '2026_06_01_000001_add_is_test_to_survey_recipients_table',
                '2026_06_01_000002_add_is_test_to_survey_responses_table',
                '2026_06_05_204010_add_category_to_surveys_table',
                '2026_06_11_000001_add_schedule_to_survey_trigger_rules',
                '2026_06_11_000002_create_survey_trigger_rule_runs_table',
                '2026_06_12_000001_add_invitation_opened_at_to_survey_recipients_table',
                '2026_06_15_000001_add_soft_deletes_to_surveys',
                '2026_06_15_000002_add_soft_deletes_to_survey_responses',
                '2026_06_18_175033_add_response_number_to_survey_responses_table',
                '2026_06_28_000001_create_google_drive_accounts_table',
                '2026_06_28_000002_add_google_drive_to_surveys_table',
                '2026_06_29_000001_create_media_table',
                '2026_07_02_000001_add_field_index_to_survey_answers_table',
                '2026_07_19_035606_create_survey_schema_versions_table',
                '2026_07_19_035608_create_survey_field_versions_table',
                '2026_07_19_035609_add_schema_version_columns_to_surveys_and_responses',
                '2026_07_19_035611_add_snapshot_columns_to_survey_answers',
                '2026_07_19_035612_add_retired_at_to_survey_fields_table',
                '2026_07_19_035614_backfill_survey_schema_versions',
                '2026_07_19_210000_add_published_requires_schema_version_check',
            ])
            ->runsMigrations()
            ->hasRoutes(['web']);
    }

    public function bootingPackage(): void
    {
        Event::listen(SurveySubmitted::class, DispatchSurveySubmittedWebhook::class);
        Event::listen(SurveySubmitted::class, SendSurveyResponseNotification::class);
        Event::listen(SurveySubmitted::class, EvaluateSurveyTriggersListener::class);
        Event::listen(SurveyTokenResolved::class, MarkTokenViewed::class);

        RateLimiter::for('survey-core-submissions', function (Request $request): Limit {
            [$attempts, $decayMinutes] = array_pad(
                explode(',', (string) config('survey-core.security.rate_limit', '60,1'), 2),
                2,
                '1',
            );

            return Limit::perMinutes(max(1, (int) $decayMinutes), max(1, (int) $attempts))
                ->by($request->ip() ?: 'guest');
        });

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/survey-core'),
        ], 'survey-core-assets');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/survey-core'),
        ], 'survey-core-views');

        if (EmailCampaignIntegration::enabled()) {
            $this->app->make(EmailCampaignIntegration::variableProviderRegistryClass())
                ->register($this->app->make(SurveyVariableProvider::class));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                SurveyScheduleCommand::class,
                PrunePartialDraftsCommand::class,
                RunTriggerRulesCommand::class,
                CheckTurnstileConfigCommand::class,
            ]);

            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);

                $schedule->command(SurveyScheduleCommand::class)
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule->command(RunTriggerRulesCommand::class)
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule->command(PrunePartialDraftsCommand::class)
                    ->daily()
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

        // Export manager with built-in CSV and XLSX drivers
        $this->app->singleton(SurveyExportManager::class, function () {
            $manager = new SurveyExportManager;
            $manager->extend('csv', fn () => new CsvSurveyExportDriver);
            $manager->extend('xlsx', fn () => new XlsxSurveyExportDriver);

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
                $app->make(SurveyFileUploadToken::class),
            );
        });

        $this->app->bind(ExportSurveyResponsesAction::class, function ($app) {
            return new ExportSurveyResponsesAction(
                $app->make(SurveyExportManager::class),
            );
        });
    }
}
