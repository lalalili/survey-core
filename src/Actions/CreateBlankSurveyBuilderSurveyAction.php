<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Models\Survey;

class CreateBlankSurveyBuilderSurveyAction
{
    public function __construct(
        private readonly SaveSurveyDraftSchemaAction $saveDraftSchema,
    ) {}

    public function execute(string $title = '未命名問卷'): Survey
    {
        return DB::transaction(function () use ($title): Survey {
            $survey = Survey::create([
                'title' => $title,
                'status' => SurveyStatus::Draft,
            ]);

            return $this->saveDraftSchema->execute($survey, [
                'id' => $survey->id,
                'title' => $title,
                'status' => SurveyStatus::Draft->value,
                'version' => $survey->version,
                'settings' => [
                    'progress' => [
                        'mode' => 'bar',
                        'show_estimated_time' => true,
                    ],
                    'show_question_numbers' => true,
                    'allow_back' => true,
                    'language' => 'zh-TW',
                    'uniqueness_mode' => 'none',
                    'anomaly' => [
                        'min_seconds' => null,
                        'detect_duplicate' => 'cookie',
                        'turnstile' => false,
                    ],
                ],
                'pages' => [
                    [
                        'id' => 'page_1',
                        'kind' => 'question',
                        'title' => '第 1 頁',
                        'jump_rules' => [],
                        'elements' => [],
                    ],
                ],
            ]);
        });
    }
}
