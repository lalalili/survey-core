<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Lalalili\SurveyCore\Enums\SurveyStatus;

class Survey extends Model
{
    protected $fillable = [
        'title',
        'description',
        'status',
        'public_key',
        'allow_anonymous',
        'allow_multiple_submissions',
        'starts_at',
        'ends_at',
        'submit_success_message',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'status' => SurveyStatus::class,
            'allow_anonymous' => 'boolean',
            'allow_multiple_submissions' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'version' => 'integer',
        ];
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

    public function save(array $options = []): bool
    {
        if (! $this->exists && empty($this->public_key)) {
            $this->public_key = Str::random(32);
        }

        return parent::save($options);
    }
}
