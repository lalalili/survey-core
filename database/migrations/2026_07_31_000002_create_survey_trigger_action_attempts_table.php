<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_trigger_action_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_trigger_action_preset_id')
                ->nullable()
                ->constrained('survey_trigger_action_presets')
                ->nullOnDelete();
            $table->foreignId('survey_trigger_dispatch_id')
                ->nullable()
                ->constrained(config('survey-core.table_names.survey_trigger_dispatches', 'survey_trigger_dispatches'))
                ->nullOnDelete();
            $table->string('action_key');
            $table->string('action_type');
            $table->string('mode', 20);
            $table->string('profile');
            $table->string('status', 32)->index();
            $table->string('ticket_no')->nullable()->index();
            $table->string('endpoint', 500);
            $table->text('request_parameters');
            $table->text('request_body');
            $table->unsignedSmallInteger('response_http_status')->nullable();
            $table->text('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->text('parsed_response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['action_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_trigger_action_attempts');
    }
};
