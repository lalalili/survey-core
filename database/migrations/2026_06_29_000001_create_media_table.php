<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // 宿主（含 spatie/laravel-medialibrary 發布的 create_media_table）可能已擁有 media 表；
        // 依時間序此 migration 在宿主之後執行，命中既有表時直接略過，避免 "table media already exists"。
        if (Schema::hasTable('media')) {
            return;
        }

        Schema::create('media', function (Blueprint $table): void {
            $table->id();

            $table->morphs('model');
            $table->uuid()->nullable();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestamps();
        });

        // SQL Server 的 unique index 視多個 NULL 為重複，需用 filtered index 排除 NULL。
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX media_uuid_unique ON media (uuid) WHERE uuid IS NOT NULL');
        } else {
            Schema::table('media', function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
