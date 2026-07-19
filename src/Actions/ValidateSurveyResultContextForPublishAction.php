<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Models\AudienceList;
use Lalalili\SurveyCore\Support\SurveyResultContextFields;
use Symfony\Component\HttpFoundation\Response;

class ValidateSurveyResultContextForPublishAction
{
    private const REQUIRED_CATEGORIES = ['CSI', 'SSI', 'IQS'];

    /**
     * @param  array<string, mixed>  $schema
     */
    public function execute(array $schema): void
    {
        $category = strtoupper(trim((string) data_get($schema, 'settings.category', '')));

        if (! in_array($category, self::REQUIRED_CATEGORIES, true)) {
            return;
        }

        $audienceListId = data_get($schema, 'settings.personalization.audience_list_id');
        if (blank($audienceListId)) {
            $this->fail('CSI、SSI、IQS 問卷發佈前，請先選擇個性化名單。');
        }

        $audienceList = AudienceList::query()->whereKey($audienceListId)->first();
        if ($audienceList === null) {
            $this->fail('選擇的個性化名單不存在，請重新選擇後再發佈。');
        }

        $schemaProfile = strtoupper(trim((string) ($audienceList->schema_profile ?? '')));
        if ($schemaProfile !== $category) {
            $this->fail("個性化名單的資料設定檔必須與問卷分類 {$category} 相同。 ");
        }

        $rawColumns = $audienceList->getAttribute('columns_json');
        $columns = collect(is_array($rawColumns) ? $rawColumns : [])->keyBy(
            fn (array $column): string => (string) ($column['key'] ?? ''),
        );
        $mappings = data_get($schema, 'settings.personalization.result_context_columns', []);
        $mappings = is_array($mappings) ? $mappings : [];

        foreach (SurveyResultContextFields::DEFINITIONS as $semantic => $definition) {
            $columnKey = trim((string) ($mappings[$semantic] ?? ''));
            if ($columnKey === '' || ! $columns->has($columnKey)) {
                $this->fail("問卷結果固定欄位「{$definition['label']}」尚未對應有效的名單欄位。 ");
            }

            if ($semantic === 'delivery_date' && data_get($columns->get($columnKey), 'type') !== 'date') {
                $this->fail('問卷結果固定欄位「交車日」必須對應日期類型的名單欄位。');
            }
        }
    }

    private function fail(string $message): never
    {
        throw new SurveyNotAvailableException(trim($message), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
