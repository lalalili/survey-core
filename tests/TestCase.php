<?php

namespace Lalalili\SurveyCore\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lalalili\PackageTestingSupport\PackageTestCase;
use Lalalili\SurveyCore\SurveyCoreServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MediaLibraryServiceProvider::class,
            SurveyCoreServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        DB::statement('PRAGMA foreign_keys = ON');
    }
}
