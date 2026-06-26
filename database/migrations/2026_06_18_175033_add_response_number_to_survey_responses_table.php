<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->string('response_number', 32)->nullable();
            $table->unique('response_number');
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->dropUnique(['response_number']);
            $table->dropColumn('response_number');
        });
    }
};
