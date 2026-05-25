<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('survey_fields', function (Blueprint $table) {
            $table->dropColumn('page');
            $table->foreignId('survey_page_id')
                ->nullable()
                ->after('show_if_value')
                ->constrained('survey_pages')
                ->nullOnDelete();

            $table->index(['survey_id', 'survey_page_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('survey_fields', function (Blueprint $table) {
            $table->dropForeign(['survey_page_id']);
            $table->dropIndex(['survey_id', 'survey_page_id', 'sort_order']);
            $table->dropColumn('survey_page_id');
            $table->unsignedSmallInteger('page')->default(1)->after('show_if_value');
        });
    }
};
