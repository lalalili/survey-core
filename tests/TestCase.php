<?php

namespace Lalalili\SurveyCore\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Monolog\Handler\NullHandler;
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

    /**
     * medialibrary 預設把檔案放在 `public` 磁碟，但套件測試環境沒有定義任何磁碟，
     * 凡是走 addMedia*() 的測試都會拋 DiskCannotBeAccessed。指向 testbench
     * 的暫存 storage 即可，測試結束由 tearDown 清掉。
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        config()->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => $this->mediaTestRoot(),
            'throw' => false,
        ]);

        // 預設 log channel 會寫進 testbench 在 vendor/ 底下的 storage：該路徑可能
        // 不可寫（例如曾被 root 執行留下的檔案），且測試不該依賴 vendor 可寫。
        config()->set('logging.default', 'null');
        config()->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/vendor/survey-core/survey.css', fn () => response(
            file_get_contents(__DIR__.'/../resources/dist/survey.css'),
            200,
            ['Content-Type' => 'text/css'],
        ));
    }

    protected function tearDown(): void
    {
        $root = $this->mediaTestRoot();

        if (is_dir($root)) {
            (new Filesystem())->deleteDirectory($root);
        }

        parent::tearDown();
    }

    private function mediaTestRoot(): string
    {
        return sys_get_temp_dir().'/survey-core-media-tests';
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

        $this->loadMigrationsFrom(__DIR__.'/../vendor/lalalili/audience-core/database/migrations');

        if (class_exists(EmailCampaignServiceProvider::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../vendor/lalalili/email-campaign/database/migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        DB::statement('PRAGMA foreign_keys = ON');
    }
}
