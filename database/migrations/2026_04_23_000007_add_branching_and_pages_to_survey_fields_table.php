<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('survey_fields', function (Blueprint $table) {
            // Branching / conditional display
            $table->string('show_if_field_key', 100)->nullable()->after('sort_order');
            $table->string('show_if_value', 255)->nullable()->after('show_if_field_key');

            // Multi-page: which page this field belongs to (1-based)
            $table->unsignedSmallInteger('page')->default(1)->after('show_if_value');
        });
    }

    public function down(): void
    {
        Schema::table('survey_fields', function (Blueprint $table) {
            $table->dropColumn(['show_if_field_key', 'show_if_value', 'page']);
        });
    }
};
