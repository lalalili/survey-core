<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyTheme extends Model
{
    protected $fillable = [
        'name',
        'tokens_json',
        'is_system',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tokens_json' => 'array',
            'is_system'   => 'boolean',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function resolvedTokens(array $overrides = []): array
    {
        return array_merge($this->tokens_json ?? [], $overrides);
    }
}
