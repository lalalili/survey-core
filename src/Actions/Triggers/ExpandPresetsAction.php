<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Lalalili\SurveyCore\Models\SurveyTriggerActionPreset;

/**
 * 將觸發規則 actions_json 中的 preset 參照展開為具體動作定義。
 *
 * 參照格式：{"type":"preset","preset_id":N}（操作員於後台下拉選擇後存入）。
 * 展開後即為 preset 的 action_json（http_post 定義，並帶入 preset 名稱）。
 * 非 preset 條目（舊有 inline http_post）原樣保留，維持向下相容。
 */
class ExpandPresetsAction
{
    /**
     * @param  array<int, array<string, mixed>>  $actionsJson
     * @return array<int, array<string, mixed>>
     */
    public function execute(array $actionsJson): array
    {
        $presetIds = collect($actionsJson)
            ->filter(fn (array $action): bool => ($action['type'] ?? '') === 'preset')
            ->pluck('preset_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        $presets = $presetIds === []
            ? collect()
            : SurveyTriggerActionPreset::query()
                ->whereIn('id', $presetIds)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

        $expanded = [];

        foreach ($actionsJson as $action) {
            if (($action['type'] ?? '') !== 'preset') {
                // 舊有 inline 動作，原樣保留。
                $expanded[] = $action;

                continue;
            }

            $preset = $presets->get((int) ($action['preset_id'] ?? 0));

            if ($preset === null) {
                // preset 不存在或已停用：略過。
                continue;
            }

            $definition = is_array($preset->action_json) ? $preset->action_json : [];
            $definition['name'] = $definition['name'] ?? $preset->name;
            $definition['_preset_id'] = (int) $preset->getKey();
            $definition['_action_key'] = 'preset:'.$preset->getKey();

            $expanded[] = $definition;
        }

        return $expanded;
    }
}
