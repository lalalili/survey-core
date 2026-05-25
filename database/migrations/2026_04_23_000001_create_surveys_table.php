<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('public_key', 64)->unique();
            $table->boolean('allow_anonymous')->default(false);
            $table->boolean('allow_multiple_submissions')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('submit_success_message')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
