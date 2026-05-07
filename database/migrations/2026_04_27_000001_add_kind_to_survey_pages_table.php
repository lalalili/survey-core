<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_pages', function (Blueprint $table) {
            $table->string('kind')->default('question')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('survey_pages', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
