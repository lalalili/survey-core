<?php

namespace Lalalili\SurveyCore\Support;

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyPage;

/**
 * Computes which survey pages are reachable given a set of submitted answers
 * and the jump logic configured in survey_fields options_json action entries.
 *
 * Returns null if the survey has no pages or no jump logic (all pages considered visited).
 * Returns a list<int> of survey_page.id values that were reached.
 *
 * 兩種跳題來源：
 *
 * 1. **選項跳題**（`survey_fields.options_json[].action`）——single_choice 與 select
 *    支援，建立器右側面板可設定，公開填答頁與建立器預覽都已實作。
 * 2. **頁面層規則**（`survey_pages.settings_json['jump_rules']`）——本類別與公開填答頁
 *    （`scripts.blade.php` 的 `PAGE_JUMP_MAP` / `resolveNextPageKey`）都完整支援，
 *    schema 往返也有處理，**但建立器沒有提供任何編輯介面**，因此正常流程不會產生
 *    這種資料（2026-08-01 實查：22 個頁面全為空）。
 *
 *    這是已知且刻意的現況，不是待辦：設計者目前只透過選項跳題設定流程。要開放
 *    頁面層規則得先補建立器 UI（可複用 builder-ui-core 的 RuleTreeBuilder），
 *    預覽端再跟上。在那之前，此處的 jump_rules 分支只對直接寫入 DB 或以 API
 *    灌入 schema 的資料生效。
 */
final class JumpLogicResolver
{
    /**
     * Walk through pages in sort_order, following jump actions based on submitted answers.
     *
     * @param  array<string, mixed>  $answers  keyed by field_key
     * @return list<int>|null list of survey_pages.id values, or null if jump logic not applicable
     */
    public static function resolveVisitedPages(Survey $survey, array $answers): ?array
    {
        $survey->loadMissing('pages', 'fields');

        $pages = $survey->pages->sortBy('sort_order')->values();

        if ($pages->isEmpty()) {
            return null;
        }

        $jumpSupportedTypes = [SurveyFieldType::SingleChoice, SurveyFieldType::Select];

        // Group fields by survey_page_id for efficient per-page lookup.
        $fieldsByPageId = $survey->fields->groupBy('survey_page_id');

        // Check whether any field or page has a non-trivial jump action.
        $hasJumpLogic = $survey->fields->contains(
            fn ($f) => in_array($f->type, $jumpSupportedTypes, true)
                && ! empty($f->options_json)
                && array_is_list($f->options_json)
                && collect($f->options_json)->contains(
                    fn ($opt) => isset($opt['action']['type']) && $opt['action']['type'] !== 'next_page',
                ),
        ) || $pages->contains(fn (SurveyPage $page): bool => ! empty($page->settings_json['jump_rules'] ?? []));

        if (! $hasJumpLogic) {
            return null;
        }

        // Build page_key → page map for jump target resolution.
        $pageByKey = $pages->keyBy('page_key');

        $visited = [];
        $currentPage = $pages->first();

        while ($currentPage instanceof SurveyPage) {
            $visited[] = $currentPage->id;

            $currentIndex = $pages->search(fn ($p) => $p->id === $currentPage->id);
            $nextPage = $pages->get($currentIndex + 1);

            // Find jump-logic fields on the current page and apply the first matching action.
            $jumpFields = ($fieldsByPageId->get($currentPage->id) ?? collect())->filter(
                fn ($f) => in_array($f->type, $jumpSupportedTypes, true),
            );

            foreach ($jumpFields as $field) {
                $value = $answers[$field->field_key] ?? null;

                if ($value === null) {
                    continue;
                }

                $action = $field->getOptionAction((string) $value);

                if (! $action) {
                    continue;
                }

                if ($action['type'] === 'end_survey') {
                    return $visited;
                }

                if ($action['type'] === 'go_to_page' && isset($action['target_page_id'])) {
                    $target = $pageByKey->get($action['target_page_id']);
                    if ($target instanceof SurveyPage) {
                        $nextPage = $target;
                    }
                }

                break; // first jump-logic field on the page determines flow
            }

            foreach (($currentPage->settings_json['jump_rules'] ?? []) as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $condition = $rule['condition'] ?? [];
                $action = $rule['action'] ?? [];

                if (! is_array($condition) || ! is_array($action)) {
                    continue;
                }

                // 沒有任何條件的跳轉規則一律略過。ConditionGroupEvaluator 對空的
                // conditions 回傳 true（顯示條件的語意是「沒設條件就顯示」），
                // 若直接沿用，一條還沒設定條件的規則就會無條件改變問卷流程。
                if (! is_array($condition['conditions'] ?? null) || $condition['conditions'] === []) {
                    continue;
                }

                if (! ConditionGroupEvaluator::passes($condition, $answers)) {
                    continue;
                }

                if (($action['type'] ?? null) === 'end_survey') {
                    return $visited;
                }

                if (($action['type'] ?? null) === 'go_to_page' && isset($action['target_page_id'])) {
                    $target = $pageByKey->get($action['target_page_id']);
                    if ($target instanceof SurveyPage) {
                        $nextPage = $target;
                    }
                }

                break;
            }

            $currentPage = $nextPage;
        }

        return $visited;
    }
}
