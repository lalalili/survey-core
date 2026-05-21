<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('external_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['survey_id', 'email']);
            $table->index(['survey_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_recipients');
    }
};
