<?php

namespace Lalalili\SurveyCore\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lalalili\AudienceCore\AudienceCoreServiceProvider;
use Lalalili\EmailCampaign\EmailCampaignServiceProvider;
use Lalalili\PackageTestingSupport\PackageTestCase;
use Lalalili\SurveyCore\SurveyCoreServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = [
            AudienceCoreServiceProvider::class,
            ActivitylogServiceProvider::class,
            MediaLibraryServiceProvider::class,
            SurveyCoreServiceProvider::class,
        ];

        if (class_exists(EmailCampaignServiceProvider::class)) {
            $providers[] = EmailCampaignServiceProvider::class;
        }

        return $providers;
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../../audience-core/database/migrations');

        if (class_exists(EmailCampaignServiceProvider::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../../email-campaign/database/migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        DB::statement('PRAGMA foreign_keys = ON');
    }
}
