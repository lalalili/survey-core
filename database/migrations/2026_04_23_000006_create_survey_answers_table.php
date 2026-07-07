<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_response_id')->constrained('survey_responses')->cascadeOnDelete();

            // SQL Server 不允許 multiple cascade paths（surveys→responses→answers 與
            // surveys→fields→answers）：field FK 改 NO ACTION，
            // 直接刪 field 前由應用層先清 answers。
            if (DB::getDriverName() === 'sqlsrv') {
                $table->foreignId('survey_field_id')->constrained('survey_fields')->noActionOnDelete();
            } else {
                $table->foreignId('survey_field_id')->constrained('survey_fields')->cascadeOnDelete();
            }
            $table->text('answer_text')->nullable();
            $table->json('answer_json')->nullable();
            $table->timestamps();

            $table->index(['survey_response_id', 'survey_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_answers');
    }
};
