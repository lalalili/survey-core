<?php

namespace Lalalili\SurveyCore\Data;

/**
 * Typed representation of surveys.settings_json.
 *
 * Serialise  : $survey->settings_json = $settings->toArray()
 * Deserialise: SurveySettings::fromArray($survey->settings_json ?? [])
 *
 * All top-level survey model columns (title, description, starts_at, ends_at,
 * max_responses, quota_message, uniqueness_mode, uniqueness_message) are stored
 * as dedicated DB columns — NOT inside settings_json.
 *
 * Any unknown key present in the raw JSON is preserved in $extra so that a
 * Builder round-trip never silently drops keys written by other tools.
 */
final class SurveySettings
{
    /**
     * @param  array<string, mixed>  $extra  Unknown keys preserved verbatim.
     */
    public function __construct(
        // ── Display ──────────────────────────────────────────────────────────
        public readonly ?string $language = null,
        public readonly bool $showQuestionNumbers = true,
        public readonly bool $allowBack = true,
        // ── Progress bar ─────────────────────────────────────────────────────
        public readonly ?string $progressMode = null,       // 'percentage'|'pages'|null
        public readonly bool $showEstimatedTime = false,
        // ── Security ─────────────────────────────────────────────────────────
        public readonly ?string $accessPassword = null,
        public readonly bool $turnstileEnabled = false,
        // ── Notifications ────────────────────────────────────────────────────
        /** @var list<string> */
        public readonly array $notifyEmails = [],
        // ── Anomaly / quality flagging ────────────────────────────────────────
        public readonly ?int $anomalyMinSeconds = null,
        public readonly string $anomalyDetectDuplicate = 'none',  // 'none'|'cookie'|'ip'|'both'
        // ── Personalization ───────────────────────────────────────────────────
        public readonly ?int $audienceListId = null,
        public readonly bool $personalizationRequired = false,
        public readonly ?string $nameColumn = null,
        public readonly ?string $emailColumn = null,
        public readonly ?string $externalIdColumn = null,
        // ── Thank-you branching ───────────────────────────────────────────────
        /** @var array<int, mixed> */
        public readonly array $thankYouBranches = [],
        // ── Catch-all for future/unknown keys ────────────────────────────────
        /** @var array<string, mixed> */
        public readonly array $extra = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        // Normalise legacy string notify_emails → array
        $notifyEmails = self::normalizeEmails(
            data_get($raw, 'notifications.notify_emails')
                ?? data_get($raw, 'notify_emails')
                ?? []
        );

        $known = [
            'language', 'show_question_numbers', 'allow_back',
            'progress', 'show_estimated_time',
            'access_password', 'turnstile_enabled',
            'notifications', 'notify_emails',
            'anomaly',
            'personalization',
            'thank_you_branches',
        ];

        $extra = array_diff_key($raw, array_flip($known));

        return new self(
            language: isset($raw['language']) ? (string) $raw['language'] : null,
            showQuestionNumbers: (bool) ($raw['show_question_numbers'] ?? true),
            allowBack: (bool) ($raw['allow_back'] ?? true),
            progressMode: data_get($raw, 'progress.mode'),
            showEstimatedTime: (bool) ($raw['show_estimated_time'] ?? false),
            accessPassword: isset($raw['access_password']) ? (string) $raw['access_password'] : null,
            turnstileEnabled: (bool) ($raw['turnstile_enabled'] ?? false),
            notifyEmails: $notifyEmails,
            anomalyMinSeconds: data_get($raw, 'anomaly.min_seconds') !== null
                ? (int) data_get($raw, 'anomaly.min_seconds')
                : null,
            anomalyDetectDuplicate: (string) (data_get($raw, 'anomaly.detect_duplicate') ?? 'none'),
            audienceListId: data_get($raw, 'personalization.audience_list_id') !== null
                ? (int) data_get($raw, 'personalization.audience_list_id')
                : null,
            personalizationRequired: (bool) data_get($raw, 'personalization.required', false),
            nameColumn: data_get($raw, 'personalization.name_column'),
            emailColumn: data_get($raw, 'personalization.email_column'),
            externalIdColumn: data_get($raw, 'personalization.external_id_column'),
            thankYouBranches: is_array($raw['thank_you_branches'] ?? null)
                ? $raw['thank_you_branches']
                : [],
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->language !== null) {
            $data['language'] = $this->language;
        }

        $data['show_question_numbers'] = $this->showQuestionNumbers;
        $data['allow_back'] = $this->allowBack;

        if ($this->progressMode !== null) {
            $data['progress']['mode'] = $this->progressMode;
        }

        if ($this->showEstimatedTime) {
            $data['show_estimated_time'] = true;
        }

        if ($this->accessPassword !== null) {
            $data['access_password'] = $this->accessPassword;
        }

        if ($this->turnstileEnabled) {
            $data['turnstile_enabled'] = true;
        }

        // Always write as notifications.notify_emails (canonical form)
        if ($this->notifyEmails !== []) {
            $data['notifications']['notify_emails'] = $this->notifyEmails;
        }

        if ($this->anomalyMinSeconds !== null || $this->anomalyDetectDuplicate !== 'none') {
            if ($this->anomalyMinSeconds !== null) {
                $data['anomaly']['min_seconds'] = $this->anomalyMinSeconds;
            }
            $data['anomaly']['detect_duplicate'] = $this->anomalyDetectDuplicate;
        }

        $personalization = [];
        if ($this->audienceListId !== null) {
            $personalization['audience_list_id'] = $this->audienceListId;
        }
        if ($this->personalizationRequired) {
            $personalization['required'] = true;
        }
        if ($this->nameColumn !== null) {
            $personalization['name_column'] = $this->nameColumn;
        }
        if ($this->emailColumn !== null) {
            $personalization['email_column'] = $this->emailColumn;
        }
        if ($this->externalIdColumn !== null) {
            $personalization['external_id_column'] = $this->externalIdColumn;
        }
        if ($personalization !== []) {
            $data['personalization'] = $personalization;
        }

        if ($this->thankYouBranches !== []) {
            $data['thank_you_branches'] = $this->thankYouBranches;
        }

        return array_merge($this->extra, $data);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function normalizeEmails(mixed $value): array
    {
        if (is_string($value) && filled($value)) {
            $emails = array_map('trim', explode(',', $value));
        } elseif (is_array($value)) {
            $emails = $value;
        } else {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $e): string => trim((string) $e), $emails),
            fn (string $e): bool => filled($e) && filter_var($e, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
