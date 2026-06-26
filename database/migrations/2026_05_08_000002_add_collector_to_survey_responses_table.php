<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('survey-core.table_names.survey_responses', 'survey_responses'), function (Blueprint $table): void {
            $table->foreignId('survey_collector_id')
                ->nullable()
                ->after('survey_token_id')
                ->constrained(config('survey-core.table_names.survey_collectors', 'survey_collectors'))
                ->nullOnDelete();

            $table->index(['survey_id', 'survey_collector_id']);
        });
    }

    public function down(): void
    {
        Schema::table(config('survey-core.table_names.survey_responses', 'survey_responses'), function (Blueprint $table): void {
            $table->dropIndex(['survey_id', 'survey_collector_id']);
            $table->dropConstrainedForeignId('survey_collector_id');
        });
    }
};
