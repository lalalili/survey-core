<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('google_drive_accounts', function (Blueprint $table) {
            $table->id();
            // 連結此帳號的後台使用者（問卷建立者）；無 owner 概念時可為 null。
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Google 帳號識別（OpenID sub），用於去重與重複綁定同一帳號。
            $table->string('google_user_id')->unique();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            // OAuth tokens — 以 Eloquent encrypted cast 加密儲存。
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_accounts');
    }
};
