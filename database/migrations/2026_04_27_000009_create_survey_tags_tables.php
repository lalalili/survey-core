<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#6366f1');
            $table->timestamps();

            $table->unique(['survey_id', 'name']);
        });

        Schema::create('survey_response_tag', function (Blueprint $table) {
            $table->foreignId('survey_response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->foreignId('survey_tag_id')->constrained('survey_tags')->cascadeOnDelete();
            $table->primary(['survey_response_id', 'survey_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_response_tag');
        Schema::dropIfExists('survey_tags');
    }
};
