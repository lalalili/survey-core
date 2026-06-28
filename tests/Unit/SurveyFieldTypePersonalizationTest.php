<?php

use Lalalili\SurveyCore\Enums\SurveyFieldType;

it('supports personalization on free-text and scalar field types', function (SurveyFieldType $type) {
    expect($type->supportsPersonalization())->toBeTrue();
})->with([
    SurveyFieldType::ShortText,
    SurveyFieldType::LongText,
    SurveyFieldType::Number,
    SurveyFieldType::Email,
    SurveyFieldType::Phone,
    SurveyFieldType::Address,
    SurveyFieldType::Date,
    SurveyFieldType::Time,
    SurveyFieldType::Hidden,
]);

it('does not support personalization on choice, matrix, rating and structured field types', function (SurveyFieldType $type) {
    expect($type->supportsPersonalization())->toBeFalse();
})->with([
    SurveyFieldType::SingleChoice,
    SurveyFieldType::MultipleChoice,
    SurveyFieldType::Select,
    SurveyFieldType::CascadeSelect,
    SurveyFieldType::Rating,
    SurveyFieldType::Nps,
    SurveyFieldType::MatrixSingle,
    SurveyFieldType::MatrixMulti,
    SurveyFieldType::Ranking,
    SurveyFieldType::LinearScale,
    SurveyFieldType::ConstantSum,
    SurveyFieldType::FileUpload,
    SurveyFieldType::Signature,
    SurveyFieldType::SectionTitle,
    SurveyFieldType::DescriptionBlock,
    SurveyFieldType::Divider,
    SurveyFieldType::QuoteBlock,
]);
