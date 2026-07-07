<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            $table->foreignId('audience_list_row_id')
                ->nullable()
                ->after('survey_id')
                ->constrained('audience_list_rows')
                ->nullOnDelete();
        });

        // SQL Server 的 unique index 視多個 NULL 為重複，需用 filtered index 排除 NULL 列。
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX survey_recipients_survey_audience_row_unique ON survey_recipients (survey_id, audience_list_row_id) WHERE audience_list_row_id IS NOT NULL');
        } else {
            Schema::table('survey_recipients', function (Blueprint $table) {
                $table->unique(['survey_id', 'audience_list_row_id'], 'survey_recipients_survey_audience_row_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            $table->dropUnique('survey_recipients_survey_audience_row_unique');
            $table->dropConstrainedForeignId('audience_list_row_id');
        });
    }
};
