<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignId('survey_recipient_id')->nullable()->constrained('survey_recipients')->nullOnDelete();
            $table->foreignId('survey_token_id')->nullable()->constrained('survey_tokens')->nullOnDelete();
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
