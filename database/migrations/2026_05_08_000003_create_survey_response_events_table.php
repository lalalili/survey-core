<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create(config('survey-core.table_names.survey_response_events', 'survey_response_events'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained(config('survey-core.table_names.surveys', 'surveys'))->cascadeOnDelete();
            // SQL Server 不允許 multiple cascade paths（survey_id 已直接 cascade，
            // collectors/responses 也從 surveys cascade 而來）：改 NO ACTION，
            // 直接刪 collector/response 前由應用層先把參照設 null。
            if (DB::getDriverName() === 'sqlsrv') {
                $table->foreignId('survey_collector_id')->nullable()->constrained(config('survey-core.table_names.survey_collectors', 'survey_collectors'))->noActionOnDelete();
                $table->foreignId('survey_response_id')->nullable()->constrained(config('survey-core.table_names.survey_responses', 'survey_responses'))->noActionOnDelete();
            } else {
                $table->foreignId('survey_collector_id')->nullable()->constrained(config('survey-core.table_names.survey_collectors', 'survey_collectors'))->nullOnDelete();
                $table->foreignId('survey_response_id')->nullable()->constrained(config('survey-core.table_names.survey_responses', 'survey_responses'))->nullOnDelete();
            }
            $table->string('event', 40);
            $table->string('page_key', 120)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'event', 'occurred_at']);
            $table->index(['survey_collector_id', 'event']);
            $table->index(['survey_response_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('survey-core.table_names.survey_response_events', 'survey_response_events'));
    }
};
