<?php

use Lalalili\SurveyCore\Support\FieldKeyGenerator;

it('generates a slug-based key with a 4-char lowercase suffix', function () {
    $key = FieldKeyGenerator::generate('Customer Name');

    expect($key)->toMatch('/^customer_name_[a-z0-9]{4}$/');
});

it('falls back to "field" for empty labels', function () {
    $key = FieldKeyGenerator::generate('');

    expect($key)->toMatch('/^field_[a-z0-9]{4}$/');
});

it('generates unique keys on repeated calls with the same label', function () {
    $keys = array_map(fn () => FieldKeyGenerator::generate('Email'), range(1, 50));

    expect(count(array_unique($keys)))->toBeGreaterThan(1);
});

it('handles special characters gracefully and produces only safe key characters', function () {
    $key = FieldKeyGenerator::generate('你好 World!');

    // non-ASCII chars are stripped by Str::slug; suffix is lowercase alphanumeric
    expect($key)->toMatch('/^[a-z0-9_]+$/');
});
