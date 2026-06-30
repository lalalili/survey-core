<?php

use Illuminate\Support\Facades\Schema;

it('skips scheduled trigger rules when schedule columns are not migrated yet', function (): void {
    Schema::table('survey_trigger_rules', function ($table): void {
        $table->dropColumn('schedule_enabled');
    });

    $this->artisan('survey:run-trigger-rules')
        ->expectsOutputToContain('Survey trigger schedule columns are not migrated yet')
        ->assertSuccessful();
});
