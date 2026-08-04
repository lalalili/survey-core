<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('survey_trigger_dms_ticket_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('profile');
            $table->string('category', 3);
            $table->date('sequence_date');
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(
                ['profile', 'category', 'sequence_date'],
                'trigger_dms_ticket_sequences_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_trigger_dms_ticket_sequences');
    }
};
