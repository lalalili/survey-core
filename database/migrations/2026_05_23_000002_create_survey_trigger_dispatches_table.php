<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('survey-core.table_names.survey_trigger_dispatches', 'survey_trigger_dispatches'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_trigger_rule_id')->constrained(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'))->cascadeOnDelete();
            $table->foreignId('survey_response_id')->constrained(config('survey-core.table_names.survey_responses', 'survey_responses'))->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->json('payload_json')->nullable();
            $table->json('response_json')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(['survey_trigger_rule_id', 'survey_response_id'], 'trigger_dispatches_rule_response_unique');
            $table->index(['survey_trigger_rule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('survey-core.table_names.survey_trigger_dispatches', 'survey_trigger_dispatches'));
    }
};
