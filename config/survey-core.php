<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model Bindings
    |--------------------------------------------------------------------------
    | Override any model to swap out the default implementation.
    */
    'models' => [
        'survey'    => \Lalalili\SurveyCore\Models\Survey::class,
        'field'     => \Lalalili\SurveyCore\Models\SurveyField::class,
        'recipient' => \Lalalili\SurveyCore\Models\SurveyRecipient::class,
        'token'     => \Lalalili\SurveyCore\Models\SurveyToken::class,
        'response'  => \Lalalili\SurveyCore\Models\SurveyResponse::class,
        'answer'    => \Lalalili\SurveyCore\Models\SurveyAnswer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */
    'table_names' => [
        'surveys'           => 'surveys',
        'survey_fields'     => 'survey_fields',
        'survey_recipients' => 'survey_recipients',
        'survey_tokens'     => 'survey_tokens',
        'survey_responses'  => 'survey_responses',
        'survey_answers'    => 'survey_answers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    */
    'route_prefix' => 'survey',

    'route_middleware' => ['web'],

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
        'min_submission_ms' => 3000,
        'ip_blacklist'      => [],
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
        'resolver' => \Lalalili\SurveyCore\Services\DefaultPersonalizationResolver::class,
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
        'timeout'   => 10,
        'tries'     => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    | Human verification widget. Obtain keys from Cloudflare Dashboard →
    | Turnstile. Set TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY in .env.
    */
    'turnstile' => [
        'site_key'   => env('TURNSTILE_SITE_KEY'),
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
