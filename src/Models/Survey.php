<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyUniquenessMode;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property SurveyStatus $status
 * @property string $public_key
 * @property bool $allow_anonymous
 * @property bool $allow_multiple_submissions
 * @property int|null $max_responses
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $submit_success_message
 * @property string|null $quota_message
 * @property SurveyUniquenessMode|null $uniqueness_mode
 * @property string|null $uniqueness_message
 * @property array<string, mixed>|null $settings_json
 * @property int|null $theme_id
 * @property array<string, mixed>|null $theme_overrides_json
 * @property int $version
 * @property array<string, mixed>|null $draft_schema
 * @property array<string, mixed>|null $published_schema
 * @property Carbon|null $published_at
 * @property-read Collection<int, SurveyPage> $pages
 * @property-read Collection<int, SurveyField> $fields
 * @property-read Collection<int, SurveyRecipient> $recipients
 * @property-read Collection<int, SurveyToken> $tokens
 * @property-read Collection<int, SurveyResponse> $responses
 * @property-read Collection<int, SurveyTag> $tags
 * @property-read SurveyTheme|null $theme
 * @property-read Collection<int, SurveyCalculation> $calculations
 */
class Survey extends Model
{
    protected $fillable = [
        'title',
        'description',
        'status',
        'public_key',
        'allow_anonymous',
        'allow_multiple_submissions',
        'max_responses',
        'starts_at',
        'ends_at',
        'submit_success_message',
        'quota_message',
        'uniqueness_mode',
        'uniqueness_message',
        'settings_json',
        'theme_id',
        'theme_overrides_json',
        'version',
        'draft_schema',
        'published_schema',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SurveyStatus::class,
            'allow_anonymous' => 'boolean',
            'allow_multiple_submissions' => 'boolean',
            'max_responses' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'uniqueness_mode' => SurveyUniquenessMode::class,
            'settings_json' => 'array',
            'theme_overrides_json' => 'array',
            'version' => 'integer',
            'draft_schema' => 'array',
            'published_schema' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SurveyPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SurveyPage::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<SurveyField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(SurveyField::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<SurveyRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(SurveyRecipient::class);
    }

    /**
     * @return HasMany<SurveyToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(SurveyToken::class);
    }

    /**
     * @return HasMany<SurveyResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    /**
     * @return HasMany<SurveyTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(SurveyTag::class);
    }

    /**
     * @return BelongsTo<SurveyTheme, $this>
     */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(SurveyTheme::class);
    }

    /**
     * @return HasMany<SurveyCalculation, $this>
     */
    public function calculations(): HasMany
    {
        return $this->hasMany(SurveyCalculation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedThemeTokens(): array
    {
        $defaults = [
            'primary' => '#6366f1',
            'accent' => '#f59e0b',
            'background' => '#ffffff',
            'surface' => '#f9fafb',
            'text' => '#111827',
            'text_muted' => '#6b7280',
            'border' => '#e5e7eb',
            'font_family' => 'system-ui, sans-serif',
            'radius' => '0.5rem',
            'button_style' => 'filled',
        ];

        if (! $this->theme) {
            return array_merge($defaults, $this->theme_overrides_json ?? []);
        }

        return array_merge($defaults, $this->theme->resolvedTokens($this->theme_overrides_json ?? []));
    }

    public function isAcceptingSubmissions(): bool
    {
        if (! $this->status->isAcceptingSubmissions()) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status->isPubliclyVisible();
    }

    public function hasQuotaAvailable(): bool
    {
        if ($this->max_responses === null) {
            return true;
        }

        return $this->responses()
            ->whereNotNull('submitted_at')
            ->count() < $this->max_responses;
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists && empty($this->public_key)) {
            $this->public_key = Str::random(32);
        }

        return parent::save($options);
    }
}
