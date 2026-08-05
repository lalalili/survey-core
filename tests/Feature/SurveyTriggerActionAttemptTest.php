<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lalalili\SurveyCore\Enums\SurveyTriggerActionAttemptStatus;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;

function createTriggerActionAttempt(array $attributes = []): SurveyTriggerActionAttempt
{
    $createdAt = $attributes['created_at'] ?? null;
    $updatedAt = $attributes['updated_at'] ?? null;
    unset($attributes['created_at'], $attributes['updated_at']);

    $attempt = SurveyTriggerActionAttempt::create(array_merge([
        'action_key' => 'dms-csi',
        'action_type' => 'dms_soap',
        'mode' => 'automatic',
        'profile' => 'qa',
        'status' => SurveyTriggerActionAttemptStatus::Success,
        'ticket_no' => 'CSI202607310001',
        'endpoint' => 'https://dms.example.test/service',
        'request_parameters' => ['sKey' => '[REDACTED]', 'name' => '王小明'],
        'request_body' => '<Envelope><sKey>[REDACTED]</sKey></Envelope>',
        'response_http_status' => 200,
        'response_headers' => ['Content-Type' => ['text/xml']],
        'response_body' => '<Envelope><Result>OK</Result></Envelope>',
        'parsed_response' => ['result' => 'OK'],
        'duration_ms' => 125,
        'sent_at' => now(),
    ], $attributes));

    if ($createdAt !== null || $updatedAt !== null) {
        $attempt->forceFill([
            'created_at' => $createdAt ?? $attempt->created_at,
            'updated_at' => $updatedAt ?? $attempt->updated_at,
        ])->saveQuietly();
    }

    return $attempt->refresh();
}

it('uses explicit short foreign key names for MySQL compatibility', function (): void {
    $createTableCallback = null;

    Schema::shouldReceive('create')
        ->once()
        ->with('survey_trigger_action_attempts', \Mockery::on(
            function (\Closure $callback) use (&$createTableCallback): bool {
                $createTableCallback = $callback;

                return true;
            },
        ));

    $migration = require __DIR__.'/../../database/migrations/2026_07_31_000002_create_survey_trigger_action_attempts_table.php';
    $migration->up();

    $connection = new MySqlConnection(null);
    $connection->useDefaultSchemaGrammar();

    $blueprint = new Blueprint($connection, 'survey_trigger_action_attempts', $createTableCallback);
    $foreignKeyStatements = array_values(array_filter(
        $blueprint->toSql(),
        fn (string $statement): bool => str_contains($statement, 'add constraint'),
    ));

    expect($foreignKeyStatements)->toHaveCount(2)
        ->and($foreignKeyStatements[0])->toContain('survey_trigger_action_attempts_preset_fk')
        ->and($foreignKeyStatements[1])->toContain('survey_trigger_action_attempts_dispatch_fk');
});

it('encrypts trigger action request and response details at rest', function (): void {
    $attempt = createTriggerActionAttempt();

    $raw = DB::table('survey_trigger_action_attempts')->find($attempt->id);

    expect($raw->request_parameters)->not->toContain('王小明')
        ->and($raw->request_body)->not->toContain('<Envelope>')
        ->and($raw->response_headers)->not->toContain('Content-Type')
        ->and($raw->response_body)->not->toContain('<Envelope>')
        ->and($raw->parsed_response)->not->toContain('result');

    $attempt->refresh();

    expect($attempt->status)->toBe(SurveyTriggerActionAttemptStatus::Success)
        ->and($attempt->request_parameters)->toBe([
            'sKey' => '[REDACTED]',
            'name' => '王小明',
        ])
        ->and($attempt->request_body)->toBe('<Envelope><sKey>[REDACTED]</sKey></Envelope>')
        ->and($attempt->response_headers)->toBe(['Content-Type' => ['text/xml']])
        ->and($attempt->response_body)->toBe('<Envelope><Result>OK</Result></Envelope>')
        ->and($attempt->parsed_response)->toBe(['result' => 'OK']);
});

it('prunes only trigger action attempts older than ninety days by default', function (): void {
    $old = createTriggerActionAttempt([
        'ticket_no' => 'CSI202604010001',
        'created_at' => now()->subDays(91),
        'updated_at' => now()->subDays(91),
    ]);
    $recent = createTriggerActionAttempt([
        'ticket_no' => 'CSI202607300001',
        'created_at' => now()->subDays(89),
        'updated_at' => now()->subDays(89),
    ]);

    $this->artisan('survey:prune-trigger-action-attempts')
        ->expectsOutputToContain('Pruned 1 trigger action attempt(s)')
        ->assertSuccessful();

    expect(SurveyTriggerActionAttempt::find($old->id))->toBeNull()
        ->and(SurveyTriggerActionAttempt::find($recent->id))->not->toBeNull();
});

it('supports overriding the trigger action attempt retention period', function (): void {
    $attempt = createTriggerActionAttempt([
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);

    $this->artisan('survey:prune-trigger-action-attempts', ['--days' => 7])
        ->assertSuccessful();

    expect(SurveyTriggerActionAttempt::find($attempt->id))->toBeNull();
});
