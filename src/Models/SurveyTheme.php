<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property array<string, mixed>|null $tokens_json
 * @property bool $is_system
 * @property int|null $owner_user_id
 */
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
            'is_system' => 'boolean',
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
