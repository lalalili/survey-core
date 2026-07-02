<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 部分環境曾手動建立此索引，補防呆避免重複建立
        if (Schema::hasIndex('survey_answers', 'survey_answers_survey_field_id_index')) {
            return;
        }

        Schema::table('survey_answers', function (Blueprint $table): void {
            // 選項容量統計以 survey_field_id 為查詢首鍵，既有 (survey_response_id, survey_field_id) 複合索引無法覆蓋
            $table->index('survey_field_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('survey_answers', 'survey_answers_survey_field_id_index')) {
            return;
        }

        Schema::table('survey_answers', function (Blueprint $table): void {
            $table->dropIndex(['survey_field_id']);
        });
    }
};
