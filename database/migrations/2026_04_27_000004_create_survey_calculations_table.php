<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('survey_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->integer('initial_value')->default(0);
            $table->string('output_format')->default('number');
            $table->json('grade_map_json')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_calculations');
    }
};
