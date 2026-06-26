<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('survey-core.table_names.survey_response_consents', 'survey_response_consents'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_response_id')->constrained(config('survey-core.table_names.survey_responses', 'survey_responses'))->cascadeOnDelete();
            $table->string('type', 40)->default('terms');
            $table->string('version', 120)->nullable();
            $table->timestamp('accepted_at');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['survey_response_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('survey-core.table_names.survey_response_consents', 'survey_response_consents'));
    }
};
