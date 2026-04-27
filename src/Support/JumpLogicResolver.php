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
 */
final class JumpLogicResolver
{
    /**
     * Walk through pages in sort_order, following jump actions based on submitted answers.
     *
     * @param  array<string, mixed>  $answers  keyed by field_key
     * @return list<int>|null  list of survey_pages.id values, or null if jump logic not applicable
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

                if (! is_array($condition) || ! is_array($action) || ! ConditionGroupEvaluator::passes($condition, $answers)) {
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
