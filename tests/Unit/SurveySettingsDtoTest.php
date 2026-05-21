<?php

use Lalalili\SurveyCore\Data\SurveySettings;

it('deserialises canonical notifications.notify_emails array', function (): void {
    $settings = SurveySettings::fromArray([
        'notifications' => ['notify_emails' => ['a@example.com', 'b@example.com']],
    ]);

    expect($settings->notifyEmails)->toBe(['a@example.com', 'b@example.com']);
});

it('normalises legacy string notify_emails into array', function (): void {
    $settings = SurveySettings::fromArray([
        'notify_emails' => 'a@example.com, b@example.com',
    ]);

    expect($settings->notifyEmails)->toBe(['a@example.com', 'b@example.com']);
});

it('filters invalid emails when normalising', function (): void {
    $settings = SurveySettings::fromArray([
        'notify_emails' => 'valid@example.com, not-an-email, other@example.com',
    ]);

    expect($settings->notifyEmails)->toBe(['valid@example.com', 'other@example.com']);
});

it('preserves unknown keys in extra', function (): void {
    $settings = SurveySettings::fromArray([
        'language' => 'zh-TW',
        'custom_widget' => ['foo' => 'bar'],
    ]);

    expect($settings->language)->toBe('zh-TW');
    expect($settings->extra)->toBe(['custom_widget' => ['foo' => 'bar']]);
});

it('round-trips toArray → fromArray without losing data', function (): void {
    $raw = [
        'language' => 'zh-TW',
        'show_question_numbers' => false,
        'allow_back' => false,
        'notifications' => ['notify_emails' => ['x@example.com']],
        'anomaly' => ['min_seconds' => 30, 'detect_duplicate' => 'cookie'],
        'personalization' => [
            'audience_list_id' => 7,
            'required' => true,
            'name_column' => 'full_name',
            'email_column' => 'email_addr',
            'external_id_column' => 'uid',
        ],
        'thank_you_branches' => [['condition' => 'score > 5', 'page_id' => 12]],
        'custom_extra' => 'preserved',
    ];

    $array = SurveySettings::fromArray($raw)->toArray();
    $restored = SurveySettings::fromArray($array);

    expect($restored->language)->toBe('zh-TW');
    expect($restored->showQuestionNumbers)->toBeFalse();
    expect($restored->allowBack)->toBeFalse();
    expect($restored->notifyEmails)->toBe(['x@example.com']);
    expect($restored->anomalyMinSeconds)->toBe(30);
    expect($restored->anomalyDetectDuplicate)->toBe('cookie');
    expect($restored->audienceListId)->toBe(7);
    expect($restored->personalizationRequired)->toBeTrue();
    expect($restored->nameColumn)->toBe('full_name');
    expect($restored->emailColumn)->toBe('email_addr');
    expect($restored->externalIdColumn)->toBe('uid');
    expect($restored->thankYouBranches)->toHaveCount(1);
    expect($restored->extra)->toHaveKey('custom_extra', 'preserved');
});

it('settingsJsonFromSchema merges with existing settings to preserve unknown keys', function (): void {
    $support = new \Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;

    $existingSettingsJson = [
        'personalization' => ['audience_list_id' => 5, 'required' => true],
        'custom_key' => 'should_survive',
    ];

    $schema = [
        'settings' => [
            'language' => 'zh-TW',
            // Builder does NOT send personalization — it doesn't know about it
        ],
    ];

    $result = $support->settingsJsonFromSchema($schema, $existingSettingsJson);

    expect($result)->toHaveKey('language', 'zh-TW');
    expect($result)->toHaveKey('custom_key', 'should_survive');
    expect(data_get($result, 'personalization.audience_list_id'))->toBe(5);
});

it('settingsJsonFromSchema still strips survey column attributes', function (): void {
    $support = new \Lalalili\SurveyCore\Support\SurveyBuilderSurveySettings;

    $schema = [
        'settings' => [
            'description' => 'ignored',
            'starts_at' => '2026-01-01T00:00',
            'ends_at' => '2026-12-31T00:00',
            'max_responses' => 100,
            'quota_message' => 'full',
            'uniqueness_mode' => 'cookie',
            'uniqueness_message' => 'dup',
            'language' => 'zh-TW',
        ],
    ];

    $result = $support->settingsJsonFromSchema($schema);

    expect($result)->toHaveKey('language');
    expect($result)->not->toHaveKey('description');
    expect($result)->not->toHaveKey('starts_at');
    expect($result)->not->toHaveKey('ends_at');
    expect($result)->not->toHaveKey('max_responses');
    expect($result)->not->toHaveKey('quota_message');
    expect($result)->not->toHaveKey('uniqueness_mode');
    expect($result)->not->toHaveKey('uniqueness_message');
});
