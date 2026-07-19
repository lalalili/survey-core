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
        Schema::table('survey_answers', function (Blueprint $table) {
            $table->string('snapshot_field_key', 120)->nullable();
            $table->string('snapshot_field_label', 500)->nullable();
            $table->string('snapshot_field_type', 50)->nullable();
            $table->json('snapshot_options_json')->nullable();
            $table->json('snapshot_settings_json')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_answers', function (Blueprint $table) {
            $table->dropColumn([
                'snapshot_field_key',
                'snapshot_field_label',
                'snapshot_field_type',
                'snapshot_options_json',
                'snapshot_settings_json',
            ]);
        });
    }
};
