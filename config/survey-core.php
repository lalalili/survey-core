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

];
