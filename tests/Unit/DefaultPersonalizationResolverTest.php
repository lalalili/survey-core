<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;
use Lalalili\SurveyCore\Models\SurveyField;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Services\DefaultPersonalizationResolver;

beforeEach(function () {
    $this->resolver = new DefaultPersonalizationResolver;
});

it('resolves a matching personalized key from recipient payload', function () {
    $recipient = new SurveyRecipient(['payload_json' => ['customer_name' => 'Alice']]);
    $field = new SurveyField(['personalized_key' => 'customer_name', 'type' => SurveyFieldType::Hidden]);

    expect($this->resolver->resolve($recipient, $field))->toBe('Alice');
});

it('returns null when the key is absent from payload', function () {
    $recipient = new SurveyRecipient(['payload_json' => []]);
    $field = new SurveyField(['personalized_key' => 'missing_key', 'type' => SurveyFieldType::Hidden]);

    expect($this->resolver->resolve($recipient, $field))->toBeNull();
});

it('returns null when personalized_key is empty', function () {
    $recipient = new SurveyRecipient(['payload_json' => ['foo' => 'bar']]);
    $field = new SurveyField(['personalized_key' => null, 'type' => SurveyFieldType::Hidden]);

    expect($this->resolver->resolve($recipient, $field))->toBeNull();
});

it('returns null when payload_json is null', function () {
    $recipient = new SurveyRecipient(['payload_json' => null]);
    $field = new SurveyField(['personalized_key' => 'customer_name', 'type' => SurveyFieldType::Hidden]);

    expect($this->resolver->resolve($recipient, $field))->toBeNull();
});
