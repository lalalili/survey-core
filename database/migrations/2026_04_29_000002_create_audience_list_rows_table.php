<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_list_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audience_list_id')->constrained('audience_lists')->cascadeOnDelete();
            $table->json('data_json');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['audience_list_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_list_rows');
    }
};
