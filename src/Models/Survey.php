<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyUniquenessMode;

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

    public function pages(): HasMany
    {
        return $this->hasMany(SurveyPage::class)->orderBy('sort_order');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SurveyField::class)->orderBy('sort_order');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(SurveyRecipient::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(SurveyToken::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(SurveyTag::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(SurveyTheme::class);
    }

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
