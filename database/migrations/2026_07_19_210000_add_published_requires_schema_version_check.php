<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 「已發佈的問卷必須有已發佈的 schema 版本」這條不變量以 CHECK 約束落到資料庫層。
 *
 * draft_schema 只有經 PublishSurveyAction 才會同步到 survey_fields / settings_json。
 * 任何直接把 status 改成 published 的路徑（曾經的 survey:schedule 就是如此）
 * 都會產出一份沒有題目的空白問卷，而且不會有任何錯誤——正是最難發現的失敗方式。
 *
 * 之所以放在資料庫層而非 model hook：Eloquent 的 mass update
 * （Survey::query()->update([...])）不觸發 model 事件，擋不住這類寫法。
 *
 * 僅 sqlsrv 建立：sqlite（單元測試）不支援以 ALTER TABLE 新增約束，
 * 該環境的保護改由 SurveyScheduleCommandTest 等測試涵蓋。
 */
return new class extends Migration
{
    private const CONSTRAINT = 'surveys_published_requires_schema_version';

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        DB::statement(
            'ALTER TABLE surveys ADD CONSTRAINT '.self::CONSTRAINT.' CHECK ('
            ."status <> 'published' OR published_schema_version_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        DB::statement('ALTER TABLE surveys DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }
};
