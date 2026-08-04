<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->string('response_number', 32)->nullable();
        });

        // SQL Server 的 unique index 視多個 NULL 為重複，需用 filtered index 排除 NULL。
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX survey_responses_response_number_unique ON survey_responses (response_number) WHERE response_number IS NOT NULL');
        } else {
            Schema::table('survey_responses', function (Blueprint $table): void {
                $table->unique('response_number');
            });
        }
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->dropUnique(['response_number']);
            $table->dropColumn('response_number');
        });
    }
};
