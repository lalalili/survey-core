<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();

            // SQL Server 不允許 multiple cascade paths（surveys→responses 直接 cascade，
            // surveys→recipients/tokens→responses 又是 SET NULL）：改 NO ACTION，
            // 直接刪 recipient/token 前由應用層先把參照設 null。
            if (DB::getDriverName() === 'sqlsrv') {
                $table->foreignId('survey_recipient_id')->nullable()->constrained('survey_recipients')->noActionOnDelete();
                $table->foreignId('survey_token_id')->nullable()->constrained('survey_tokens')->noActionOnDelete();
            } else {
                $table->foreignId('survey_recipient_id')->nullable()->constrained('survey_recipients')->nullOnDelete();
                $table->foreignId('survey_token_id')->nullable()->constrained('survey_tokens')->nullOnDelete();
            }
            $table->timestamp('submitted_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('completion_status', 20)->default('complete');
            $table->timestamps();

            $table->index(['survey_id', 'submitted_at']);
            $table->index(['survey_id', 'survey_recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
