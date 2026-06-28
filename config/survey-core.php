<?php

use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyResponseConsent;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;
use Lalalili\SurveyCore\Models\SurveyToken;
use Lalalili\SurveyCore\Services\DefaultPersonalizationResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Model Bindings
    |--------------------------------------------------------------------------
    | Override any model to swap out the default implementation.
    */
    'models' => [
        'survey' => Survey::class,
        'field' => SurveyField::class,
        'recipient' => SurveyRecipient::class,
        'token' => SurveyToken::class,
        'response' => SurveyResponse::class,
        'answer' => SurveyAnswer::class,
        'collector' => SurveyCollector::class,
        'response_event' => SurveyResponseEvent::class,
        'response_consent' => SurveyResponseConsent::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */
    'table_names' => [
        'surveys' => 'surveys',
        'survey_fields' => 'survey_fields',
        'survey_recipients' => 'survey_recipients',
        'survey_tokens' => 'survey_tokens',
        'survey_responses' => 'survey_responses',
        'survey_answers' => 'survey_answers',
        'survey_collectors' => 'survey_collectors',
        'survey_response_events' => 'survey_response_events',
        'survey_response_consents' => 'survey_response_consents',
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    */
    'route_prefix' => 'survey',

    'route_middleware' => ['web'],

    'collectors' => [
        'route_prefix' => 's',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    */

    /** Minimum 32 characters. */
    'token_length' => 64,

    /**
     * Token lifetime in minutes. null = tokens never expire by default.
     * Individual tokens may override this at generation time.
     */
    'token_lifetime_minutes' => null,

    /**
     * Default max submissions per token. null = unlimited.
     */
    'default_max_submissions' => null,

    /*
    |--------------------------------------------------------------------------
    | Submissions
    |--------------------------------------------------------------------------
    */
    'default_allow_multiple_submissions' => false,

    'security' => [
        'rate_limit' => '60,1',
        'turnstile_verify' => true,
        'sanitize_html' => true,
        'min_submission_ms' => 3000,
        'ip_blacklist' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone Validation
    |--------------------------------------------------------------------------
    | Regex patterns and error messages for phone / mobile fields, keyed by
    | locale. A field may override the locale via settings_json['phone_locale'];
    | otherwise `default_locale` applies. Defaults preserve the Taiwan 09 format.
    */
    'phone' => [
        'default_locale' => env('SURVEY_PHONE_LOCALE', 'tw'),
        'patterns' => [
            'tw' => '/^09\d{8}$/',
            'intl' => '/^\+?[1-9]\d{6,14}$/',
        ],
        'messages' => [
            'tw' => '請輸入 09 開頭的 10 碼手機號碼。',
            'intl' => '請輸入有效的電話號碼（可含國碼，例如 +886912345678）。',
        ],
    ],

    'analytics' => [
        'retention_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    */
    'exports' => [
        'default_driver' => 'csv',
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    | 檔案上傳會先建立一筆 Partial 暫存草稿回覆承載媒體。送出後媒體會被搬到
    | 正式回覆並立即刪除已搬空的草稿；未送出而遭放棄的草稿，則由
    | `survey:prune-partial-drafts` 於超過保留時數後清除。
    */
    'uploads' => [
        'partial_draft_retention_hours' => 24,
        'token_ttl_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Drive 綁定
    |--------------------------------------------------------------------------
    | 含檔案上傳題的問卷需綁定一個 Google Drive 帳號，填答上傳的檔案會非同步
    | 推送到該問卷的 Drive 資料夾。client_id/secret 需於 Google Cloud 建立
    | OAuth client 並啟用 Drive API，redirect URI 需與 Google Console 註冊一致。
    */
    'google_drive' => [
        'enabled' => (bool) env('GOOGLE_DRIVE_ENABLED', true),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),
        // drive.file：只能存取本 App 建立的檔案/資料夾（最小權限）。
        'scopes' => ['https://www.googleapis.com/auth/drive.file', 'openid', 'email', 'profile'],
        'delete_local_after_upload' => (bool) env('GOOGLE_DRIVE_DELETE_LOCAL', false),
        'queue' => env('GOOGLE_DRIVE_QUEUE'),
    ],

    'builder_images' => [
        'disk' => env('SURVEY_BUILDER_IMAGES_DISK', 'public'),
        'max_size' => (int) env('SURVEY_BUILDER_IMAGES_MAX_SIZE', 5120),
        'accepted_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Personalization
    |--------------------------------------------------------------------------
    | The resolver that maps recipient payload fields onto survey hidden fields.
    | Must implement \Lalalili\SurveyCore\Contracts\PersonalizationResolver.
    */
    'personalization' => [
        'resolver' => DefaultPersonalizationResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional Integrations
    |--------------------------------------------------------------------------
    | Set email_campaign.enabled to false to keep survey-core from registering
    | email-campaign variable providers or using the tracked transactional mail
    | pipeline, even when lalalili/email-campaign is installed.
    */
    'integrations' => [
        'email_campaign' => [
            'enabled' => env('SURVEY_EMAIL_CAMPAIGN_ENABLED'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | Email notification sent when a new response is submitted.
    |
    | 'new_response_notify_emails' — list of email addresses that receive a
    |     notification for every submitted response (global).  Set to [] to
    |     disable.  Per-survey recipients can override via survey settings_json.
    */
    'notifications' => [
        'new_response_notify_emails' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trigger Actions
    |--------------------------------------------------------------------------
    | HTTP trigger headers may reference selected environment variables with
    | {{env.KEY}} tokens. Keep this list narrow to avoid exposing unrelated
    | process environment values through user-configured webhook headers.
    */
    'triggers' => [
        'header_env_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SURVEY_TRIGGER_HEADER_ENV_KEYS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    | A list of endpoint URLs that receive a POST payload whenever a survey
    | response is submitted.  Each entry may be a plain URL string, or an
    | associative array with 'url' and optional 'secret' (used to sign the
    | payload with HMAC-SHA256 in the X-Survey-Signature header).
    |
    | Example:
    |   'endpoints' => [
    |       'https://example.com/hook',
    |       ['url' => 'https://other.com/hook', 'secret' => 'my-secret'],
    |   ],
    */
    'webhooks' => [
        'endpoints' => [],
        'timeout' => 10,
        'tries' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    | Human verification widget. Obtain keys from Cloudflare Dashboard →
    | Turnstile. Set TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY in .env.
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Assets
    |--------------------------------------------------------------------------
    | Controls how the public survey page loads its stylesheet.
    |
    | 'cdn'       — loads Tailwind CSS via CDN <script> (zero-setup, good for dev)
    | 'published' — uses the CSS published to public/vendor/survey-core/survey.css
    |               Run: php artisan vendor:publish --tag=survey-core-assets
    |
    | Any other string is treated as an absolute URL for a custom stylesheet.
    */
    'frontend' => [
        'css' => 'cdn',
    ],

];
