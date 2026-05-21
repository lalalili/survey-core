<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_themes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('tokens_json');
            $table->boolean('is_system')->default(false);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_themes');
    }
};
