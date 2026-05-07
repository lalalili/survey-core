<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->json('settings_json')->nullable()->after('submit_success_message');
            $table->foreignId('theme_id')->nullable()->after('settings_json')->constrained('survey_themes')->nullOnDelete();
            $table->json('theme_overrides_json')->nullable()->after('theme_id');
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('theme_id');
            $table->dropColumn(['settings_json', 'theme_overrides_json']);
        });
    }
};
