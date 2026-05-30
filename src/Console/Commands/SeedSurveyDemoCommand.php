<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Lalalili\SurveyCore\Actions\ImportSurveyBuilderSchemaAction;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyToken;
use Lalalili\SurveyCore\Models\SurveyTriggerActionPreset;
use Lalalili\SurveyCore\Models\SurveyTriggerAllowedHost;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;

/**
 * 塞入問卷 Demo 資料（問卷結構、觸發規則、白名單）。
 * 以 examples/abc-vehicle-owner-survey.builder.json 為模板，前兩題改為個性化。
 * 結束時將 survey_id 回綁至 MarketingActivity 與 ActivityTemplate。
 * 可重複執行（upsert）。
 */
class SeedSurveyDemoCommand extends Command
{
    protected $signature = 'survey:seed-demo
                            {--fresh : 先刪除舊的 demo 問卷資料再重建}';

    protected $description = '塞入問卷 Demo 資料（問卷結構、觸發規則、白名單）';

    private const CSI_SURVEY_TITLE = '服務滿意度 售後服務滿意度調查（Demo）';

    private const SSI_SURVEY_TITLE = '銷售滿意度 銷售滿意度調查（Demo）';

    public function handle(ImportSurveyBuilderSchemaAction $importAction): int
    {
        if ($this->option('fresh')) {
            $this->cleanup();
        }

        $this->info('=== 建立 服務滿意度 問卷 ===');
        $csiSurvey = $this->ensureSurvey($importAction, self::CSI_SURVEY_TITLE, 'csi');

        $this->info('=== 建立 銷售滿意度 問卷 ===');
        $ssiSurvey = $this->ensureSurvey($importAction, self::SSI_SURVEY_TITLE, 'ssi');

        $this->info('=== 建立 DMS 動作設定（觸發動作預設）===');
        $presets = $this->seedActionPresets();

        $this->info('=== 建立觸發規則 ===');
        $this->seedTriggerRules($csiSurvey, $presets);
        $this->seedTriggerRules($ssiSurvey, $presets);

        $this->info('=== 建立觸發白名單 ===');
        $this->seedAllowedHosts();

        $this->info('=== 回綁 survey_id 至發送設定 + 活動模板 ===');
        $this->bindSurveyIdToActivities($csiSurvey, $ssiSurvey);

        $this->info('');
        $this->info('✓ 問卷結構 Demo 資料完成！（名單/Token/回應由 marketing:seed-demo-dispatches 建立）');
        $this->info('  後台入口：/admin/surveys');

        return self::SUCCESS;
    }

    private function ensureSurvey(ImportSurveyBuilderSchemaAction $importAction, string $title, string $listType): Survey
    {
        $existing = Survey::where('title', $title)->first();

        if ($existing) {
            $this->line("  沿用既有問卷：{$title}（ID {$existing->id}）");

            return $existing;
        }

        $schema = $this->buildSchema($listType);
        $schema['title'] = $title;

        $survey = $importAction->execute($schema, $title, publish: true);
        $this->line("  建立問卷：{$title}（ID {$survey->id}）");

        return $survey;
    }

    /**
     * 載入 abc-vehicle-owner-survey.builder.json 並將 page_basic 前兩題改為個性化隱藏欄位。
     *
     * 個性化欄位對應：
     *   - purchase_service_center → dept（服務/銷售部門）
     *   - vehicle_plate_number    → regono（車牌號碼）
     *
     * @return array<string, mixed>
     */
    private function buildSchema(string $listType): array
    {
        $examplePath = __DIR__ . '/../../../examples/abc-vehicle-owner-survey.builder.json';

        /** @var array<string, mixed> $schema */
        $schema = json_decode((string) file_get_contents($examplePath), true, flags: JSON_THROW_ON_ERROR);

        foreach ($schema['pages'] as &$page) {
            if (($page['id'] ?? '') !== 'page_basic') {
                continue;
            }

            foreach ($page['elements'] as &$element) {
                if (($element['field_key'] ?? '') === 'purchase_service_center') {
                    $element['is_hidden'] = true;
                    $element['personalized_key'] = 'dept';
                    $element['required'] = false;
                    $element['label'] = $listType === 'csi' ? '服務部門' : '銷售部門';
                }

                if (($element['field_key'] ?? '') === 'vehicle_plate_number') {
                    $element['is_hidden'] = true;
                    $element['personalized_key'] = 'regono';
                    $element['required'] = false;
                    $element['label'] = '車牌號碼';
                }
            }
            unset($element);
        }
        unset($page);

        return $schema;
    }

    /**
     * 建立兩個系統管理員預設的 DMS 觸發動作，供觸發規則以下拉選單參照。
     *
     * @return array<string, SurveyTriggerActionPreset>  以 key 索引
     */
    private function seedActionPresets(): array
    {
        $presets = [
            [
                'key'         => 'dms_case',
                'name'        => '顧關立案',
                'description' => '低分填答自動於 DMS 建立顧客關懷案件',
                'action_json' => [
                    'type'                => 'http_post',
                    'name'                => '顧關立案',
                    'endpoint'            => 'https://example.com/webhook/dms-case',
                    'headers'             => ['Authorization' => 'Bearer {{env.DMS_TOKEN}}'],
                    'payload_template'    => [
                        'source'      => 'survey',
                        'response_id' => '{{response.id}}',
                        'mobile'      => '{{recipient.payload.mobile}}',
                        'license'     => '{{recipient.payload.regono}}',
                        'nps'         => '{{answer.vehicle_recommend_nps}}',
                    ],
                    'timeout'             => 10,
                    'retry'               => ['times' => 3, 'sleep_ms' => 200],
                    // 顧管立案需對匿名公開填答也反應，維持 false。
                    'require_valid_token' => false,
                ],
            ],
            [
                'key'         => 'repair_voucher',
                'name'        => '贈送維修抵用劵',
                'description' => '完成回填於 DMS 發送維修抵用劵（限有效邀請連結）',
                'action_json' => [
                    'type'                => 'http_post',
                    'name'                => '贈送維修抵用劵',
                    'endpoint'            => 'https://example.com/webhook/repair-voucher',
                    'headers'             => ['Authorization' => 'Bearer {{env.DMS_TOKEN}}'],
                    'payload_template'    => [
                        'source'      => 'survey',
                        'response_id' => '{{response.id}}',
                        'mobile'      => '{{recipient.payload.mobile}}',
                        'license'     => '{{recipient.payload.regono}}',
                        'owner_name'  => '{{recipient.payload.username}}',
                    ],
                    'timeout'             => 10,
                    'retry'               => ['times' => 3, 'sleep_ms' => 200],
                    // 發點券：限「邀請連結（token）且未逾期」填答（7 天由 token 視窗把關）。
                    'require_valid_token' => true,
                ],
            ],
        ];

        $result = [];

        foreach ($presets as $def) {
            $preset = SurveyTriggerActionPreset::firstOrCreate(
                ['key' => $def['key']],
                [
                    'name'        => $def['name'],
                    'description' => $def['description'],
                    'action_json' => $def['action_json'],
                    'is_active'   => true,
                ],
            );
            $result[$def['key']] = $preset;
            $this->line("  DMS 動作：{$def['name']}（{$def['key']}）");
        }

        return $result;
    }

    /**
     * @param  array<string, SurveyTriggerActionPreset>  $presets
     */
    private function seedTriggerRules(Survey $survey, array $presets): void
    {
        $rules = [
            [
                'name' => '低分顧關立案（Demo）',
                // 規則樹採編輯器格式 {op, children}，後台「篩選條件」可正確顯示與編輯。
                'rule_tree_json' => [
                    'op'       => 'AND',
                    'children' => [
                        ['field' => 'vehicle_recommend_nps', 'operator' => '<=', 'value' => '6'],
                    ],
                ],
                'actions_json' => [
                    ['type' => 'preset', 'preset_id' => $presets['dms_case']->id],
                ],
            ],
            [
                'name' => '7天內回填贈送維修抵用劵（Demo）',
                // 「回填」＝填答到最後的推薦題（NPS）視為完成；7 天由動作的 require_valid_token + token 視窗把關。
                'rule_tree_json' => [
                    'op'       => 'AND',
                    'children' => [
                        ['field' => 'vehicle_recommend_nps', 'operator' => 'is_not_null', 'value' => null],
                    ],
                ],
                'actions_json' => [
                    ['type' => 'preset', 'preset_id' => $presets['repair_voucher']->id],
                ],
            ],
        ];

        foreach ($rules as $def) {
            SurveyTriggerRule::firstOrCreate(
                ['survey_id' => $survey->id, 'name' => $def['name']],
                [
                    'is_active'       => true,
                    'rule_tree_json'  => $def['rule_tree_json'],
                    'actions_json'    => $def['actions_json'],
                    'triggered_count' => 0,
                ],
            );
            $this->line("  觸發規則：{$def['name']}（{$survey->title}）");
        }
    }

    private function seedAllowedHosts(): void
    {
        $hosts = [
            ['host' => 'example.com', 'description' => 'Demo webhook 目標域名'],
            ['host' => 'localhost', 'description' => '本機開發測試'],
        ];

        foreach ($hosts as $data) {
            SurveyTriggerAllowedHost::firstOrCreate(
                ['host' => $data['host']],
                ['description' => $data['description']],
            );
            $this->line("  白名單：{$data['host']}");
        }
    }

    private function bindSurveyIdToActivities(Survey $csiSurvey, Survey $ssiSurvey): void
    {
        if (! class_exists(\Lalalili\MarketingAutomation\Models\MarketingActivity::class)) {
            return;
        }

        $csiCount = \Lalalili\MarketingAutomation\Models\MarketingActivity::where('name', 'like', '%服務滿意度%（Demo）')
            ->update(['survey_id' => $csiSurvey->id]);
        $ssiCount = \Lalalili\MarketingAutomation\Models\MarketingActivity::where('name', 'like', '%銷售滿意度%（Demo）')
            ->update(['survey_id' => $ssiSurvey->id]);

        $this->line("  MarketingActivity 綁定：服務滿意度 {$csiCount} 筆、銷售滿意度 {$ssiCount} 筆");

        if (! class_exists(\Lalalili\MarketingAutomation\Models\ActivityTemplate::class)) {
            return;
        }

        $csiTpl = \Lalalili\MarketingAutomation\Models\ActivityTemplate::where('name', 'like', '%服務滿意度%（Demo）')
            ->update(['survey_id' => $csiSurvey->id]);
        $ssiTpl = \Lalalili\MarketingAutomation\Models\ActivityTemplate::where('name', 'like', '%銷售滿意度%（Demo）')
            ->update(['survey_id' => $ssiSurvey->id]);

        $this->line("  ActivityTemplate 綁定：服務滿意度 {$csiTpl} 筆、銷售滿意度 {$ssiTpl} 筆");
    }

    private function cleanup(): void
    {
        $this->warn('清除舊 Demo 問卷資料...');

        foreach ([self::CSI_SURVEY_TITLE, self::SSI_SURVEY_TITLE] as $title) {
            $survey = Survey::where('title', $title)->first();

            if (! $survey) {
                continue;
            }

            // Trigger dispatches before rules
            $ruleIds = SurveyTriggerRule::where('survey_id', $survey->id)->pluck('id');
            $responseIds = SurveyResponse::where('survey_id', $survey->id)->pluck('id');
            SurveyTriggerDispatch::whereIn('survey_trigger_rule_id', $ruleIds)
                ->whereIn('survey_response_id', $responseIds)
                ->delete();
            SurveyTriggerRule::where('survey_id', $survey->id)->delete();

            SurveyAnswer::whereIn('survey_response_id', $responseIds)->delete();
            SurveyResponse::whereIn('id', $responseIds)->delete();
            SurveyToken::where('survey_id', $survey->id)->delete();
            SurveyRecipient::where('survey_id', $survey->id)->delete();

            $survey->fields()->delete();
            $survey->pages()->delete();
            $survey->delete();

            $this->line("  清除：{$title}");
        }

        SurveyTriggerAllowedHost::whereIn('host', ['example.com', 'localhost'])->delete();
        SurveyTriggerActionPreset::whereIn('key', ['dms_case', 'repair_voucher'])->delete();

        // Clear survey_id bindings so activities show "未綁定" until next dispatch seed
        if (class_exists(\Lalalili\MarketingAutomation\Models\MarketingActivity::class)) {
            \Lalalili\MarketingAutomation\Models\MarketingActivity::where('name', 'like', '%（Demo）')
                ->update(['survey_id' => null]);
        }

        if (class_exists(\Lalalili\MarketingAutomation\Models\ActivityTemplate::class)) {
            \Lalalili\MarketingAutomation\Models\ActivityTemplate::where('name', 'like', '%（Demo）')
                ->update(['survey_id' => null]);
        }

        $this->warn('清除完成。');
    }
}
