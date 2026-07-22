<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'), function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('survey-core.table_names.survey_trigger_rules', 'survey_trigger_rules'), function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
