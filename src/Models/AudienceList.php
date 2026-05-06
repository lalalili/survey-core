<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AudienceList extends Model
{
    protected $fillable = [
        'name',
        'description',
        'columns_json',
        'rows_count',
        'imported_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'columns_json' => 'array',
            'rows_count'   => 'integer',
            'imported_at'  => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AudienceListRow::class);
    }

    /**
     * @return array<string, string>
     */
    public function columnOptions(): array
    {
        return collect($this->columns_json ?? [])
            ->mapWithKeys(fn (string $column): array => [$column => $column])
            ->all();
    }
}
