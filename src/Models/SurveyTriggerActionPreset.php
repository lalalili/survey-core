<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Model;
use Lalalili\AudienceCore\Concerns\LogsModelActivity;

/**
 * 系統管理員預先設定的觸發動作（具名的 http_post 定義），
 * 供觸發規則以下拉選單參照，操作員無需手填 endpoint / payload。
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $action_json
 * @property bool $is_active
 */
class SurveyTriggerActionPreset extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'key',
        'name',
        'description',
        'action_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'action_json' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
