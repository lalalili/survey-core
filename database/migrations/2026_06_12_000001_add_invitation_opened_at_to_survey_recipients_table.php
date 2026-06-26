<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            $table->timestamp('invitation_opened_at')->nullable()->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            $table->dropColumn('invitation_opened_at');
        });
    }
};
