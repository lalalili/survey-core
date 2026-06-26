<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_fields', function (Blueprint $table) {
            $table->json('settings_json')->nullable()->after('validation_rules');
        });
    }

    public function down(): void
    {
        Schema::table('survey_fields', function (Blueprint $table) {
            $table->dropColumn('settings_json');
        });
    }
};
