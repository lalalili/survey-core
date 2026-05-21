<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('survey_tokens', function (Blueprint $table): void {
            $table->timestamp('viewed_at')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('survey_tokens', function (Blueprint $table): void {
            $table->dropColumn('viewed_at');
        });
    }
};
