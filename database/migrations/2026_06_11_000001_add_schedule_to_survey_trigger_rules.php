<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'), function (Blueprint $table): void {
            $table->boolean('schedule_enabled')->default(false)->after('is_active');
            $table->string('schedule_time', 5)->nullable()->after('schedule_enabled');
            $table->unsignedInteger('schedule_window_days')->default(7)->after('schedule_time');
            $table->timestamp('last_scheduled_run_at')->nullable()->after('schedule_window_days');
        });
    }

    public function down(): void
    {
        Schema::table(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'), function (Blueprint $table): void {
            $table->dropColumn([
                'schedule_enabled',
                'schedule_time',
                'schedule_window_days',
                'last_scheduled_run_at',
            ]);
        });
    }
};
