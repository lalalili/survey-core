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
        Schema::create('survey_field_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schema_version_id')->constrained('survey_schema_versions')->cascadeOnDelete();
            $table->foreignId('survey_field_id')->nullable()->constrained('survey_fields')->noActionOnDelete();
            $table->string('element_id');
            $table->string('field_key');
            $table->string('label', 500);
            $table->string('type', 50);
            $table->json('options_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['schema_version_id', 'field_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_field_versions');
    }
};
