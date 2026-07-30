<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Exceptions\DmsConfigurationException;
use Lalalili\SurveyCore\Models\DmsTicketSequence;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;

final class DmsTicketNumberAllocator
{
    public function execute(
        string $profile,
        string $category,
        CarbonInterface $date,
        ?SurveyTriggerDispatch $dispatch = null,
        ?string $actionKey = null,
    ): string {
        $category = strtoupper($category);

        if (! in_array($category, ['SSI', 'CSI', 'IQS'], true)) {
            throw new DmsConfigurationException('DMS ticket category must be SSI, CSI, or IQS.');
        }

        if ($dispatch instanceof SurveyTriggerDispatch && filled($actionKey)) {
            $existing = SurveyTriggerActionAttempt::query()
                ->where('survey_trigger_dispatch_id', $dispatch->getKey())
                ->where('action_key', $actionKey)
                ->whereNotNull('ticket_no')
                ->latest('id')
                ->value('ticket_no');

            if (is_string($existing) && $existing !== '') {
                return $existing;
            }
        }

        $sequenceDate = $date->format('Y-m-d');
        $ticketDate = $date->format('Ymd');

        return DB::transaction(function () use ($profile, $category, $sequenceDate, $ticketDate): string {
            $next = DB::connection()->getDriverName() === 'sqlsrv'
                ? $this->allocateSqlServerSequence(
                    DB::connection(),
                    $profile,
                    $category,
                    $sequenceDate,
                )
                : $this->allocateSequence($profile, $category, $sequenceDate);

            if ($next === null) {
                throw new DmsConfigurationException(
                    "DMS ticket sequence exhausted for {$profile}/{$category}/{$sequenceDate}.",
                );
            }

            return $category.$ticketDate.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        }, 3);
    }

    private function allocateSequence(string $profile, string $category, string $sequenceDate): ?int
    {
        DmsTicketSequence::query()->insertOrIgnore([[
            'profile' => $profile,
            'category' => $category,
            'sequence_date' => $sequenceDate,
            'last_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $sequence = DmsTicketSequence::query()
            ->where('profile', $profile)
            ->where('category', $category)
            ->whereDate('sequence_date', $sequenceDate)
            ->lockForUpdate()
            ->firstOrFail();

        if ($sequence->last_sequence >= 999999) {
            return null;
        }

        $next = $sequence->last_sequence + 1;
        $sequence->update(['last_sequence' => $next]);

        return $next;
    }

    private function allocateSqlServerSequence(
        ConnectionInterface $connection,
        string $profile,
        string $category,
        string $sequenceDate,
    ): ?int {
        $timestamp = now()->format('Y-m-d H:i:s.v');
        $result = $connection->selectOne(
            <<<'SQL'
                MERGE survey_trigger_dms_ticket_sequences WITH (HOLDLOCK) AS target
                USING (
                    SELECT
                        CAST(? AS nvarchar(255)) AS profile,
                        CAST(? AS nvarchar(3)) AS category,
                        CAST(? AS date) AS sequence_date
                ) AS source
                ON target.profile = source.profile
                    AND target.category = source.category
                    AND target.sequence_date = source.sequence_date
                WHEN MATCHED AND target.last_sequence < 999999 THEN
                    UPDATE SET
                        last_sequence = target.last_sequence + 1,
                        updated_at = ?
                WHEN NOT MATCHED THEN
                    INSERT (profile, category, sequence_date, last_sequence, created_at, updated_at)
                    VALUES (source.profile, source.category, source.sequence_date, 1, ?, ?)
                OUTPUT inserted.last_sequence;
                SQL,
            [$profile, $category, $sequenceDate, $timestamp, $timestamp, $timestamp],
        );

        $next = data_get($result, 'last_sequence');

        return is_numeric($next) ? (int) $next : null;
    }
}
