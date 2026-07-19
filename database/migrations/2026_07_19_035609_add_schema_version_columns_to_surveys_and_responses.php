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
        Schema::table('surveys', function (Blueprint $table) {
            $table->foreignId('published_schema_version_id')
                ->nullable()
                ->constrained('survey_schema_versions')
                ->noActionOnDelete();
        });

        Schema::table('survey_responses', function (Blueprint $table) {
            $table->foreignId('schema_version_id')
                ->nullable()
                ->constrained('survey_schema_versions')
                ->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schema_version_id');
        });

        Schema::table('surveys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_schema_version_id');
        });
    }
};
