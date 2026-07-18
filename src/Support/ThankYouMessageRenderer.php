<?php

namespace Lalalili\SurveyCore\Support;

use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyPage;
use Lalalili\SurveyCore\Models\SurveyResponse;

/**
 * 組出送出後的感謝訊息與分支感謝頁。
 *
 * 由 PublicSurveyController 抽出：訊息插值（calc / response_number 變數、rich editor
 * autolink 與變數晶片正規化）與 thank_you_branches 分支選頁皆為純邏輯，與 HTTP 無關，
 * 集中於此以縮小 controller 並便於測試。
 */
class ThankYouMessageRenderer
{
    /**
     * @param  array<string, mixed>  $calculations
     */
    public function messageFor(Survey $survey, array $calculations, ?SurveyResponse $response = null): string
    {
        $survey->loadMissing('pages');

        $thankYouPage = $this->pageFor($survey, $calculations);
        $message = $thankYouPage?->settings_json['thank_you']['message']
            ?? $survey->submit_success_message
            ?? '感謝您的填寫！';

        $message = $this->normalizeCalculationTokens($this->normalizeVariableTokenChips((string) $message));

        $message = preg_replace_callback('/\{\{\s*calc\.([A-Za-z0-9_\-]+)\s*\}\}/', function (array $matches) use ($calculations): string {
            return (string) ($calculations[$matches[1]] ?? '');
        }, $message) ?? $message;

        $responseNumber = $response instanceof SurveyResponse ? (string) $response->response_number : '';

        return preg_replace('/\{\{\s*response_number\s*\}\}/', $responseNumber, $message) ?? $message;
    }

    /**
     * @param  array<string, mixed>  $calculations
     */
    public function pageFor(Survey $survey, array $calculations): ?SurveyPage
    {
        $survey->loadMissing('pages');
        $thankYouPages = $survey->pages->filter(fn (SurveyPage $page): bool => $page->kind->value === 'thank_you');

        foreach (($survey->settings_json['thank_you_branches'] ?? []) as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $condition = $branch['condition'] ?? [];
            $pageId = $branch['page_id'] ?? data_get($branch, 'action.target_page_id');

            if (is_array($condition) && ConditionGroupEvaluator::passes($this->calculationConditionGroup($condition), $calculations)) {
                $target = $thankYouPages->first(fn ($page): bool => $page->page_key === $pageId);
                if ($target) {
                    return $target;
                }
            }
        }

        return $thankYouPages->first();
    }

    private function normalizeVariableTokenChips(string $message): string
    {
        return preg_replace_callback('/<span\b[^>]*\bdata-variable-token=(["\'])(.*?)\1[^>]*>.*?<\/span>/is', function (array $matches): string {
            $token = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (preg_match('/^\{\{\s*calc\.[A-Za-z0-9_\-]+\s*\}\}$/', $token) !== 1) {
                return (string) $matches[0];
            }

            return $token;
        }, $message) ?? $message;
    }

    private function normalizeCalculationTokens(string $message): string
    {
        return preg_replace_callback('/\{\{(.*?)\}\}/s', function (array $matches): string {
            $inner = html_entity_decode(strip_tags((string) $matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $inner = preg_replace('/\s+/', ' ', trim($inner)) ?? trim($inner);

            if (preg_match('/^calc\.([A-Za-z0-9_\-]+)$/', $inner, $calcMatches) !== 1) {
                return (string) $matches[0];
            }

            return '{{ calc.'.$calcMatches[1].' }}';
        }, $message) ?? $message;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private function calculationConditionGroup(array $condition): array
    {
        if (isset($condition['calc_key'])) {
            return [
                'logic' => 'and',
                'conditions' => [[
                    'field_key' => (string) $condition['calc_key'],
                    'op' => (string) ($condition['op'] ?? 'equals'),
                    'value' => $condition['value'] ?? null,
                ]],
            ];
        }

        return $condition;
    }
}
