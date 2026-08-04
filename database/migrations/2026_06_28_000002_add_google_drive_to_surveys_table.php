<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            // 一個問卷僅能綁定一個雲端硬碟帳號。
            $table->foreignId('google_drive_account_id')
                ->nullable()
                ->after('category')
                ->constrained('google_drive_accounts')
                ->nullOnDelete();
            // App 為此問卷在 Drive 建立的資料夾 id（檔案上傳目的地）。
            $table->string('google_drive_folder_id')->nullable()->after('google_drive_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('google_drive_account_id');
            $table->dropColumn('google_drive_folder_id');
        });
    }
};
