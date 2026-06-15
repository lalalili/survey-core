<?php

namespace Lalalili\SurveyCore\Console\Commands;

use Illuminate\Console\Command;
use Lalalili\SurveyCore\Actions\EvaluateAnswerRuleTreeAction;
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
    private const ACTIVITY_TEMPLATE_CLASS = 'Lalalili\\MarketingAutomation\\Models\\ActivityTemplate';

    private const MARKETING_ACTIVITY_CLASS = 'Lalalili\\MarketingAutomation\\Models\\MarketingActivity';

    protected $signature = 'survey:seed-demo
                            {--fresh : 先刪除舊的 demo 問卷資料再重建}';

    protected $description = '塞入問卷 Demo 資料（問卷結構、觸發規則、白名單）';

    private const CSI_SURVEY_TITLE = '售後服務滿意度問卷（Demo）';

    private const SSI_SURVEY_TITLE = '銷售滿意度問卷（Demo）';

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
        $examplePath = __DIR__.'/../../../examples/abc-vehicle-owner-survey.builder.json';

        /** @var array<string, mixed> $schema */
        $schema = json_decode((string) file_get_contents($examplePath), true, flags: JSON_THROW_ON_ERROR);

        // 銷售滿意度（SSI）：完全對齊客戶「銷售滿意度問卷.xlsx」——僅保留 10 題銷售題，
        // 移除模板額外的「車輛使用體驗」頁，並逐題校正題目文字。
        if ($listType === 'ssi') {
            $keepPages = ['page_welcome', 'page_basic', 'page_sales_core', 'page_sales_test_drive', 'page_sales_delivery'];
            $schema['pages'] = array_values(array_filter(
                $schema['pages'],
                fn (array $page): bool => in_array($page['id'] ?? '', $keepPages, true),
            ));
        }

        $ssiLabels = $this->ssiQuestionLabels();

        foreach ($schema['pages'] as &$page) {
            foreach ($page['elements'] as &$element) {
                $key = $element['field_key'] ?? '';

                if ($key === 'purchase_service_center') {
                    $element['is_hidden'] = true;
                    $element['personalized_key'] = 'dept';
                    $element['required'] = false;
                    $element['label'] = $listType === 'csi' ? '服務部門' : '您購車的展示服務中心:';
                }

                if ($key === 'vehicle_plate_number') {
                    $element['is_hidden'] = true;
                    $element['personalized_key'] = 'regono';
                    $element['required'] = false;
                    $element['label'] = $listType === 'csi'
                        ? '車牌號碼'
                        : '您愛車的牌照號碼 (請正確填寫以避免保修抵用金無法入帳):';
                }

                // SSI 銷售題逐題對齊客戶原始問卷文字。
                if ($listType === 'ssi' && isset($ssiLabels[$key])) {
                    $element['label'] = $ssiLabels[$key];
                }

                // 試乘/試駕體驗滿意度（3-2）僅在「有試乘/試駕（是）」時顯示，對齊客戶問卷分支，
                // 使未試駕者不需作答此必填題、且彙整平均僅計實際試駕者。
                if ($listType === 'ssi' && $key === 'sales_test_drive_satisfaction') {
                    $element['show_if_field_key'] = 'sales_test_drive_experience';
                    $element['show_if_value'] = 'yes';
                }
            }
            unset($element);
        }
        unset($page);

        return $schema;
    }

    /**
     * 客戶「銷售滿意度問卷.xlsx」10 題銷售題的原始題目文字（field_key => label）。
     *
     * @return array<string, string>
     */
    private function ssiQuestionLabels(): array
    {
        return [
            'sales_overall_satisfaction'                    => '1. 請問您對於此次購車過程整體滿意度？(1~10分，10分最高分)',
            'sales_vehicle_intro_satisfaction'              => '2. 請問您對於銷售顧問在車輛介紹及服務積極滿意度？(1~10分，10分最高分)',
            'sales_test_drive_experience'                   => '3. 請問您本次購車是否有試乘/試駕體驗？',
            'sales_test_drive_satisfaction'                 => '請問您對於銷售顧問在試乘/試駕體驗說明及服務積極滿意度？(1~10分，10分最高分)',
            'sales_charging_knowledge_satisfaction'         => '4. 請問您對於銷售顧問在充電服務等專業知識說明及服務積極滿意度？(1~10分，10分最高分)',
            'sales_pre_delivery_service_satisfaction'       => '5. 請問您對於銷售顧問在交車前，相關家用充電、保險、分期、配件等手續辦理服務滿意度？(1~10分，10分最高分)',
            'sales_delivery_checklist_satisfaction'         => '6. 請問交車時對銷售顧問按照交車確認單，逐一說明車輛功能的滿意度？(1~10分，10分最高分)',
            'sales_service_center_intro_satisfaction'       => '7. 請問交車時對銷售顧問介紹服務中心聯絡方式及服務時間的滿意度？(1~10分，10分最高分)',
            'sales_delivery_vehicle_condition_satisfaction' => '8. 請問您對於交車當天車輛外觀、內裝狀況滿意度？(1~10分，10分最高分)',
            'sales_post_delivery_follow_up'                 => '9. 請問於交車後，銷售顧問是否主動關懷您車輛的使用狀況？(是/否)',
            'sales_purchase_delivery_feedback'              => '10. 本次 購車、交車的過程中，若有任何建議，邀請您回饋',
        ];
    }

    /**
     * 建立兩個系統管理員預設的 DMS 觸發動作，供觸發規則以下拉選單參照。
     *
     * @return array<string, SurveyTriggerActionPreset> 以 key 索引
     */
    private function seedActionPresets(): array
    {
        $presets = [
            [
                'key'         => 'dms_case',
                'name'        => '顧關立案',
                'description' => '低分填答自動於 DMS 建立顧客關懷案件',
                'action_json' => [
                    'type'             => 'http_post',
                    'name'             => '顧關立案',
                    'endpoint'         => 'https://example.com/webhook/dms-case',
                    'headers'          => ['Authorization' => 'Bearer {{env.DMS_TOKEN}}'],
                    'payload_template' => [
                        'source'      => 'survey',
                        'response_id' => '{{response.id}}',
                        'mobile'      => '{{recipient.payload.mobile}}',
                        'license'     => '{{recipient.payload.regono}}',
                        'nps'         => '{{answer.vehicle_recommend_nps}}',
                    ],
                    'timeout' => 10,
                    'retry'   => ['times' => 3, 'sleep_ms' => 200],
                    // 顧管立案需對匿名公開填答也反應，維持 false。
                    'require_valid_token' => false,
                ],
            ],
            [
                'key'         => 'repair_voucher',
                'name'        => '贈送維修抵用劵',
                'description' => '完成回填於 DMS 發送維修抵用劵（限有效邀請連結）',
                'action_json' => [
                    'type'             => 'http_post',
                    'name'             => '贈送維修抵用劵',
                    'endpoint'         => 'https://example.com/webhook/repair-voucher',
                    'headers'          => ['Authorization' => 'Bearer {{env.DMS_TOKEN}}'],
                    'payload_template' => [
                        'source'      => 'survey',
                        'response_id' => '{{response.id}}',
                        'mobile'      => '{{recipient.payload.mobile}}',
                        'license'     => '{{recipient.payload.regono}}',
                        'owner_name'  => '{{recipient.payload.username}}',
                    ],
                    'timeout' => 10,
                    'retry'   => ['times' => 3, 'sleep_ms' => 200],
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
                // 明寫「回填距邀請 ≤ 7 天」；有回覆即送（不另設完成條件）。
                // 此「回填距邀請天數」是獎勵積極回填的門檻，與發送活動的「填答有效天數」
                // （response_window_days，問卷填答時限）是不同用途、刻意設不一樣：
                // demo 問卷開放 30 天可填，但只有 7 天內回填者才送券。require_valid_token 再防呆。
                'rule_tree_json' => [
                    'op'       => 'AND',
                    'children' => [
                        ['field' => EvaluateAnswerRuleTreeAction::META_DAYS_SINCE_INVITATION, 'operator' => '<=', 'value' => '7'],
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
        if (! class_exists(self::MARKETING_ACTIVITY_CLASS)) {
            $this->line('  MarketingActivity 綁定：略過（未安裝 lalalili/marketing-automation）');

            return;
        }

        $marketingActivityClass = self::MARKETING_ACTIVITY_CLASS;

        $csiCount = $marketingActivityClass::where('name', 'like', '%服務滿意度%（Demo）')
            ->update(['survey_id' => $csiSurvey->id]);
        $ssiCount = $marketingActivityClass::where('name', 'like', '%銷售滿意度%（Demo）')
            ->update(['survey_id' => $ssiSurvey->id]);

        $this->line("  MarketingActivity 綁定：服務滿意度 {$csiCount} 筆、銷售滿意度 {$ssiCount} 筆");

        if (! class_exists(self::ACTIVITY_TEMPLATE_CLASS)) {
            $this->line('  ActivityTemplate 綁定：略過（未安裝 lalalili/marketing-automation）');

            return;
        }

        $activityTemplateClass = self::ACTIVITY_TEMPLATE_CLASS;

        $csiTpl = $activityTemplateClass::where('name', 'like', '%服務滿意度%（Demo）')
            ->update(['survey_id' => $csiSurvey->id]);
        $ssiTpl = $activityTemplateClass::where('name', 'like', '%銷售滿意度%（Demo）')
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
        if (class_exists(self::MARKETING_ACTIVITY_CLASS)) {
            $marketingActivityClass = self::MARKETING_ACTIVITY_CLASS;

            $marketingActivityClass::where('name', 'like', '%（Demo）')
                ->update(['survey_id' => null]);
        }

        if (class_exists(self::ACTIVITY_TEMPLATE_CLASS)) {
            $activityTemplateClass = self::ACTIVITY_TEMPLATE_CLASS;

            $activityTemplateClass::where('name', 'like', '%（Demo）')
                ->update(['survey_id' => null]);
        }

        $this->warn('清除完成。');
    }
}
