<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();

            // SQL Server 不允許 multiple cascade paths（surveys→tokens 與
            // surveys→recipients→tokens 都是 cascade）：recipient FK 改 NO ACTION，
            // 直接刪 recipient 前由應用層先清 tokens；刪 survey 走 survey_id cascade。
            if (DB::getDriverName() === 'sqlsrv') {
                $table->foreignId('survey_recipient_id')->constrained('survey_recipients')->noActionOnDelete();
            } else {
                $table->foreignId('survey_recipient_id')->constrained('survey_recipients')->cascadeOnDelete();
            }
            $table->string('token', 128)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_submissions')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_tokens');
    }
};
