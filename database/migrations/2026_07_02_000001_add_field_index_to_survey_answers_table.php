<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // 部分環境曾手動建立此索引，補防呆避免重複建立
        if ($this->hasIndex('survey_answers', 'survey_answers_survey_field_id_index')) {
            return;
        }

        Schema::table('survey_answers', function (Blueprint $table): void {
            // 選項容量統計以 survey_field_id 為查詢首鍵，既有 (survey_response_id, survey_field_id) 複合索引無法覆蓋
            $table->index('survey_field_id');
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex('survey_answers', 'survey_answers_survey_field_id_index')) {
            return;
        }

        Schema::table('survey_answers', function (Blueprint $table): void {
            $table->dropIndex(['survey_field_id']);
        });
    }

    /**
     * Schema::hasIndex() 在 sqlsrv 底層用 STRING_AGG 聚合欄位名稱，
     * SQL Server 2016（無 STRING_AGG，2017+ 才有）會直接噴錯；這裡只需確認索引是否存在。
     */
    private function hasIndex(string $table, string $index): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlsrv') {
            return Schema::hasIndex($table, $index);
        }

        return (bool) DB::selectOne(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.indexes WHERE name = ? AND object_id = OBJECT_ID(?)) THEN 1 ELSE 0 END AS idx_exists',
            [$index, $table],
        )->idx_exists;
    }
};
