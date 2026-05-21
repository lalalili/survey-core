<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('page_key', 100);
            $table->string('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'page_key']);
            $table->index(['survey_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_pages');
    }
};
