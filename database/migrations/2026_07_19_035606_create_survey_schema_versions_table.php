<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('survey_schema_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->noActionOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema_json');
            $table->string('source', 50);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'version']);
            $table->index(['survey_id', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_schema_versions');
    }
};
