<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_personalized')->default(false);
            $table->string('personalized_key', 100)->nullable();
            $table->string('field_key', 100);
            $table->string('placeholder')->nullable();
            $table->text('default_value')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('options_json')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['survey_id', 'field_key']);
            $table->index(['survey_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_fields');
    }
};
