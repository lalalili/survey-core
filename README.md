# survey-core

Laravel 問卷系統核心套件。提供完整的問卷引擎（token 機制、個性化欄位、多頁問卷、跳題邏輯、CSV 匯出），無任何 Filament 依賴，可在純 Laravel 專案中使用。

## 功能

- 問卷與題目管理（狀態機：Draft → Published → Closed）
- 個性化 token 連結：收件人 URL 攜帶 token，後端解析並注入個性化欄位值
- **跳題邏輯**：`show_if_field_key` + `show_if_value`，前後端雙重驗證
- **多頁問卷**：題目依 `page` 欄位分組，前端逐頁填寫，單次提交
- CSV 匯出（可擴充至 xlsx 等格式）
- Events hook 點（SurveyViewed / SurveyTokenResolved / SurveySubmitted / SurveyClosed）

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
| `token_length` | Token 長度（最小 32） | `64` |
| `token_lifetime_minutes` | Token 有效期（分鐘，null = 永不過期） | `null` |
| `default_max_submissions` | 每個 token 最多提交次數（null = 不限） | `null` |
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
```

Token 透過 query string 傳入：`?t={token}`

### Actions

```php
use Lalalili\SurveyCore\Actions\PublishSurveyAction;
use Lalalili\SurveyCore\Actions\GenerateSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Actions\ExportSurveyResponsesAction;

// 發佈問卷
app(PublishSurveyAction::class)->execute($survey);

// 產生 token（建立個性化連結）
$token = app(GenerateSurveyTokenAction::class)->execute($survey, $recipient);
$url = route('survey.show', $survey->public_key) . '?t=' . $token->token;

// 匯出（回傳 StreamedResponse）
return app(ExportSurveyResponsesAction::class)->execute($survey);
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
