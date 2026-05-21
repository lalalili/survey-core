<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignId('survey_recipient_id')->constrained('survey_recipients')->cascadeOnDelete();
            $table->string('token', 128)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_submissions')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_tokens');
    }
};
