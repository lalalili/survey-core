<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained(config('survey-core.table_names.surveys', 'surveys'))->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->json('rule_tree_json');
            $table->json('actions_json');
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedBigInteger('triggered_count')->default(0);
            $table->timestamps();

            $table->index(['survey_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'));
    }
};
