<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create(config('survey-core.table_names.survey_trigger_rule_runs', 'survey_trigger_rule_runs'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_trigger_rule_id')->constrained(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'))->cascadeOnDelete();
            $table->string('trigger_type', 20);
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('scanned_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('dispatched_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['survey_trigger_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('survey-core.table_names.survey_trigger_rule_runs', 'survey_trigger_rule_runs'));
    }
};
