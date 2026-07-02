<?php

namespace Lalalili\SurveyCore\Support;

use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyField;

class SurveyOptionUsageCounter
{
    /**
     * 統計欄位中指定選項值的已用數量（answer_text 精確匹配或 answer_json 陣列包含）。
     *
     * 以最多兩次查詢取代逐選項 COUNT：單值答案交給 DB group by 聚合，
     * 複選（answer_json 陣列）僅取回包含任一目標值的列後在 PHP 端統計。
     *
     * @param  array<int, string>  $optionValues
     * @return array<string, int>
     */
    public static function count(SurveyField $field, array $optionValues): array
    {
        if ($optionValues === []) {
            return [];
        }

        $usage = array_fill_keys($optionValues, 0);

        $textCounts = SurveyAnswer::query()
            ->where('survey_field_id', $field->id)
            ->whereIn('answer_text', $optionValues)
            ->groupBy('answer_text')
            ->selectRaw('answer_text, count(*) as aggregate')
            ->pluck('aggregate', 'answer_text');

        foreach ($textCounts as $value => $count) {
            $usage[(string) $value] = (int) $count;
        }

        SurveyAnswer::query()
            ->where('survey_field_id', $field->id)
            ->whereNotNull('answer_json')
            ->where(function ($query) use ($optionValues): void {
                foreach ($optionValues as $value) {
                    $query->orWhereJsonContains('answer_json', $value);
                }
            })
            ->select(['id', 'answer_text', 'answer_json'])
            ->lazy()
            ->each(function (SurveyAnswer $answer) use (&$usage, $optionValues): void {
                $selected = array_map('strval', (array) $answer->answer_json);

                foreach ($optionValues as $value) {
                    // answer_text 相同值已在聚合查詢計入，避免同列重複計數
                    if ($answer->answer_text === $value) {
                        continue;
                    }

                    if (in_array($value, $selected, true)) {
                        $usage[$value]++;
                    }
                }
            });

        return $usage;
    }
}
