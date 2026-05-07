# survey-core

Laravel 問卷系統核心套件。提供完整的問卷引擎（token 機制、個性化欄位、多頁問卷、跳題邏輯、安全提交、collector 追蹤、事件漏斗、CSV 匯出），無任何 Filament 依賴，可在純 Laravel 專案中使用。

## 功能

- 問卷與題目管理（狀態機：Draft → Published → Closed）
- 個性化 token 連結：收件人 URL 攜帶 token，後端解析並注入個性化欄位值
- **跳題邏輯**：`show_if_field_key` + `show_if_value`，前後端雙重驗證
- **多頁問卷**：題目依 `page` 欄位分組，前端逐頁填寫，單次提交
- CSV 匯出（可擴充至 xlsx 等格式）
- 商用安全基礎：後端密碼驗證、Turnstile server-side verification、terms consent 記錄、匿名/token 強制規則、route throttle、最短填寫時間檢查
- Collector 與事件漏斗：`web_link` / `email_invite` / `qr_code` / `embed_iframe` 等回收入口可用獨立 slug，提交與事件可保存 collector attribution
- Analytics action：彙總總回應、開始/提交/完成率、每日趨勢、collector 成效、選擇題/NPS/rating 單題分佈
- Events hook 點（SurveyViewed / SurveyStarted / SurveyTokenResolved / SurveySubmitted / SurveyClosed）

## 安裝

```bash
composer require lalalili/survey-core
php artisan migrate
```

> ServiceProvider 透過 `spatie/laravel-package-tools` 自動載入。

## 設定

發布設定檔：

```bash
php artisan vendor:publish --tag=survey-core-config
```

關鍵設定項目（`config/survey-core.php`）：

| 鍵 | 說明 | 預設 |
|---|---|---|
| `route_prefix` | 公開填寫頁 URL 前綴 | `survey` |
| `route_middleware` | 套用在公開頁的 middleware | `['web']` |
| `collectors.route_prefix` | collector 短連結 URL 前綴 | `s` |
| `token_length` | Token 長度（最小 32） | `64` |
| `token_lifetime_minutes` | Token 有效期（分鐘，null = 永不過期） | `null` |
| `default_max_submissions` | 每個 token 最多提交次數（null = 不限） | `null` |
| `security.rate_limit` | submit/upload/events/password route throttle 設定 | `60,1` |
| `security.turnstile_verify` | 是否啟用 Turnstile server-side verification | `true` |
| `security.sanitize_html` | rich content sanitize 開關（供後續 schema pipeline 使用） | `true` |
| `security.min_submission_ms` | 最短填寫時間檢查門檻 | `3000` |
| `analytics.retention_days` | 事件資料保留天數預設值 | `365` |
| `exports.default_driver` | 匯出驅動（`csv`） | `csv` |
| `personalization.resolver` | 個性化 resolver 類別（可替換） | `DefaultPersonalizationResolver` |
| `frontend.css` | 公開頁 CSS 來源（`cdn`、`published`、或自訂 URL） | `cdn` |

### 前端資產

公開填寫頁預設使用 Tailwind CDN。若要改為 self-hosted：

```bash
php artisan vendor:publish --tag=survey-core-assets
```

這會將 `public/vendor/survey-core/survey.css` 複製到 host 專案。接著在 `config/survey-core.php` 設定：

```php
'frontend' => ['css' => 'published'],
```

若想完全自訂 Blade view：

```bash
php artisan vendor:publish --tag=survey-core-views
```

## 使用

### 公開端點

```
GET  /survey/{publicKey}           → 問卷填寫頁
POST /survey/{publicKey}/submit    → 提交答案
POST /survey/{publicKey}/upload    → 上傳檔案題附件
POST /survey/{publicKey}/events    → 記錄 started/page_viewed/submitted/abandoned 等漏斗事件
POST /survey/{publicKey}/password  → 後端驗證問卷密碼
GET  /s/{collectorSlug}            → Collector 入口，轉入公開問卷並綁定 attribution
POST /s/{collectorSlug}/password   → Collector 入口的密碼驗證
```

Token 透過 query string 傳入：`?t={token}`

若 `surveys.allow_anonymous = false` 或問卷設定要求個性化 token，公開頁、提交與事件 API 都會拒絕未帶有效 token 的請求。

### Collector

Collector 用於行銷活動、Email invite、QR code、嵌入 iframe 等不同回收入口。每個 collector 有獨立 slug 與 tracking 設定：

```php
use Lalalili\SurveyCore\Models\SurveyCollector;

$collector = SurveyCollector::create([
    'survey_id' => $survey->id,
    'type' => 'qr_code',
    'name' => '春季活動 QR',
    'slug' => 'spring-event-qr',
    'tracking_json' => [
        'utm_source' => 'event',
        'utm_campaign' => 'spring-2026',
    ],
]);

$url = route('survey.collector.show', $collector->slug);
```

提交回應會寫入 `survey_responses.survey_collector_id`，事件漏斗會寫入 `survey_response_events.survey_collector_id`。

若安裝 `lalalili/survey-filament`，可在問卷檢視/編輯頁的「回收管道」關聯管理器建立與管理 collectors。

### 安全設定

- 密碼保護透過 `POST /survey/{publicKey}/password` 或 `POST /s/{collectorSlug}/password` 在後端驗證，不會把明文密碼輸出到 HTML/JS。
- Turnstile 啟用條件為問卷 `settings_json.anomaly.turnstile = true` 且 `TURNSTILE_SECRET_KEY` 可用。提交時後端會呼叫 Cloudflare siteverify。
- 若設定 `settings_json.terms_text`，提交必須帶 `_terms_accepted = true`，並在 `survey_response_consents` 記錄同意版本與時間。
- `settings_json.security.min_submission_ms` 可覆寫全域最短填寫時間門檻。

### Actions

```php
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;
use Lalalili\SurveyCore\Actions\ComputeSurveyAnalyticsAction;

// 發佈問卷
app(PublishSurveyAction::class)->execute($survey);

// 產生 token（建立個性化連結）
$token = app(GenerateSurveyTokenAction::class)->execute($survey, $recipient);
$url = route('survey.show', $survey->public_key) . '?t=' . $token->token;

// 匯出（回傳 StreamedResponse）
return app(ExportSurveyResponsesAction::class)->execute($survey);

// 分析資料（可供 Filament、API 或自訂報表共用）
$analytics = app(ComputeSurveyAnalyticsAction::class)->execute($survey);
```

### 個性化 resolver 替換

```php
// config/survey-core.php
'personalization' => [
    'resolver' => App\Services\MyPersonalizationResolver::class,
],

// App\Services\MyPersonalizationResolver
use Lalalili\SurveyCore\Contracts\PersonalizationResolver;

class MyPersonalizationResolver implements PersonalizationResolver
{
    public function resolve(SurveyRecipient $recipient, SurveyField $field): mixed
    {
        return $recipient->payload_json[$field->personalized_key] ?? null;
    }
}
```

### 跳題邏輯（Branching）

在 `SurveyField` 設定：

```php
SurveyField::create([
    'survey_id' => $survey->id,
    'label' => '請說明原因',
    'type' => SurveyFieldType::LongText,
    'show_if_field_key' => 'nps_score',   // 觸發欄位的 field_key
    'show_if_value' => '3',               // 當答案等於此值時顯示
    'page' => 1,
]);
```

後端驗證會自動跳過不符合條件的欄位（不驗證 `required`，不儲存答案）。

### 多頁問卷

將 `page` 設定不同數字即可：

```php
// 第一頁：基本資料
SurveyField::create(['label' => '姓名', 'page' => 1, ...]);

// 第二頁：滿意度評分
SurveyField::create(['label' => '整體滿意度', 'page' => 2, ...]);
```

前端自動顯示頁次指示器與「上一頁 / 下一頁」導覽，所有答案在最後一頁一次提交。

## Events

| Event | Payload | 用途 |
|---|---|---|
| `SurveyViewed` | `survey, ?recipient` | 觀測瀏覽 |
| `SurveyTokenResolved` | `token, recipient` | CRM 同步 hook |
| `SurveyStarted` | `survey, ?recipient` | 漏斗統計 |
| `SurveySubmitted` | `response, survey, ?recipient` | 通知 / 資料同步 |
| `SurveyClosed` | `survey` | Webhook |

```php
// app/Providers/EventServiceProvider.php
use Lalalili\SurveyCore\Events\SurveySubmitted;

Event::listen(SurveySubmitted::class, function ($event) {
    // $event->response, $event->survey, $event->recipient
});
```

## 測試

```bash
cd packages/survey-core
composer install
./vendor/bin/pest --compact
```
