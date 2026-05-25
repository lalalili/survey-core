<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            $table->foreignId('audience_list_row_id')
                ->nullable()
                ->after('survey_id')
                ->constrained('audience_list_rows')
                ->nullOnDelete();

            $table->unique(['survey_id', 'audience_list_row_id'], 'survey_recipients_survey_audience_row_unique');
        });
    }

    public function down(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            $table->dropUnique('survey_recipients_survey_audience_row_unique');
            $table->dropConstrainedForeignId('audience_list_row_id');
        });
    }
};
