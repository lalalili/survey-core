<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            // 問卷分類：SSI 銷售 / CSI 服務 / IQS 新車品質。驅動角色資料範圍。
            $table->string('category', 10)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
