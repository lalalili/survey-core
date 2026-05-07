<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->unsignedInteger('max_responses')->nullable()->after('allow_multiple_submissions');
            $table->text('quota_message')->nullable()->after('submit_success_message');
            $table->string('uniqueness_mode')->default('none')->after('quota_message');
            $table->string('uniqueness_message')->nullable()->after('uniqueness_mode');
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropColumn([
                'max_responses',
                'quota_message',
                'uniqueness_mode',
                'uniqueness_message',
            ]);
        });
    }
};
