<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $google_user_id
 * @property string|null $email
 * @property string|null $name
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property array<int, string>|null $scopes
 */
class GoogleDriveAccount extends Model
{
    protected $fillable = [
        'user_id',
        'google_user_id',
        'email',
        'name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /**
     * @return HasMany<Survey, $this>
     */
    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
