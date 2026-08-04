<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $tableName = config('survey-core.table_names.survey_trigger_dispatches', 'survey_trigger_dispatches');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('action_key')->nullable()->after('survey_response_id');
        });

        DB::table($tableName)
            ->whereNull('action_key')
            ->update(['action_key' => 'legacy']);

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('action_key')->default('legacy')->nullable(false)->change();
            $table->dropUnique('trigger_dispatches_rule_response_unique');
            $table->unique(
                ['survey_trigger_rule_id', 'survey_response_id', 'action_key'],
                'trigger_dispatches_rule_response_action_unique',
            );
        });
    }

    public function down(): void
    {
        $tableName = config('survey-core.table_names.survey_trigger_dispatches', 'survey_trigger_dispatches');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropUnique('trigger_dispatches_rule_response_action_unique');
            $table->unique(
                ['survey_trigger_rule_id', 'survey_response_id'],
                'trigger_dispatches_rule_response_unique',
            );
            $table->dropColumn('action_key');
        });
    }
};
