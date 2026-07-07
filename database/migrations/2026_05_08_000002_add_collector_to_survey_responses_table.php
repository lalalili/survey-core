<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('survey-core.table_names.survey_responses', 'survey_responses'), function (Blueprint $table): void {
            // SQL Server 不允許 multiple cascade paths（surveys→responses 直接 cascade，
            // surveys→collectors→responses 又是 SET NULL）：改 NO ACTION，
            // 刪 collector 前由應用層先把參照設 null。
            if (DB::getDriverName() === 'sqlsrv') {
                $table->foreignId('survey_collector_id')
                    ->nullable()
                    ->after('survey_token_id')
                    ->constrained(config('survey-core.table_names.survey_collectors', 'survey_collectors'))
                    ->noActionOnDelete();
            } else {
                $table->foreignId('survey_collector_id')
                    ->nullable()
                    ->after('survey_token_id')
                    ->constrained(config('survey-core.table_names.survey_collectors', 'survey_collectors'))
                    ->nullOnDelete();
            }

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
