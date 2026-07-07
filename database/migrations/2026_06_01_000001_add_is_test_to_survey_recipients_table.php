<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('survey_recipients', function (Blueprint $table) {
            // SQL Server 不允許直接 drop 仍被 index 依賴的欄位，先卸 index。
            $table->dropIndex(['is_test']);
            $table->dropColumn('is_test');
        });
    }
};
