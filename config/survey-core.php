<?php

use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyAnswer;
use Lalalili\SurveyCore\Models\SurveyCollector;
use Lalalili\SurveyCore\Models\SurveyResponseConsent;
use Lalalili\SurveyCore\Models\SurveyResponseEvent;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
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
