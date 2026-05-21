<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('survey-core.table_names.survey_collectors', 'survey_collectors'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained(config('survey-core.table_names.surveys', 'surveys'))->cascadeOnDelete();
            $table->string('type', 40)->default('web_link')->index();
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->json('settings_json')->nullable();
            $table->json('tracking_json')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('survey-core.table_names.survey_collectors', 'survey_collectors'));
    }
};
