<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Lalalili\SurveyCore\Enums\DmsExecutionMode;
use Lalalili\SurveyCore\Enums\DmsParameterConfirmation;
use Lalalili\SurveyCore\Exceptions\DmsConfigurationException;

final class ValidateDmsActionConfiguration
{
    /**
     * @var list<string>
     */
    private const REQUIRED_CONFIRMATIONS = [
        'open_method_id',
        'category_path',
        'employee_code_source',
        'description_format',
        'response_semantics',
        'ticket_type_id',
        'open_department_code',
        'gender_mapping',
        'ticket_number_strategy',
        'wsdl_contract',
    ];

    /**
     * @param  array<string, mixed>  $action
     */
    public function execute(array $action, DmsExecutionMode $mode): void
    {
        $profile = (string) ($action['profile'] ?? '');

        if (! in_array($profile, ['qa', 'production'], true)) {
            throw new DmsConfigurationException('DMS profile must be qa or production.');
        }

        $profileConfig = config("survey-core.triggers.dms.profiles.{$profile}");

        if (! is_array($profileConfig)
            || blank($profileConfig['endpoint'] ?? null)
            || blank($profileConfig['key'] ?? null)) {
            throw new DmsConfigurationException("DMS {$profile} profile is incomplete.");
        }

        if ($mode !== DmsExecutionMode::Automatic) {
            return;
        }

        $confirmations = is_array($action['parameter_confirmations'] ?? null)
            ? $action['parameter_confirmations']
            : [];

        foreach (self::REQUIRED_CONFIRMATIONS as $parameter) {
            if (($confirmations[$parameter] ?? null) !== DmsParameterConfirmation::Confirmed->value) {
                throw new DmsConfigurationException("DMS parameter [{$parameter}] is not confirmed.");
            }
        }

        foreach (['open_method_id', 'employee_code'] as $parameter) {
            if (blank($action[$parameter] ?? null)) {
                throw new DmsConfigurationException("DMS parameter [{$parameter}] is required.");
            }
        }

        if (blank($action['category_path'] ?? null)
            && blank($action['category_paths'] ?? null)) {
            throw new DmsConfigurationException('DMS parameter [category_path] is required.');
        }

        if (blank($action['open_question_key'] ?? null)
            && blank($action['open_question_keys'] ?? null)) {
            throw new DmsConfigurationException('DMS open question field mapping is required.');
        }

        if (blank($action['description_template'] ?? null)
            && blank($action['description_templates'] ?? null)) {
            throw new DmsConfigurationException('DMS description template is required.');
        }

        if ($profile !== 'production') {
            throw new DmsConfigurationException('Automatic DMS actions must use the production profile.');
        }

        if (blank($profileConfig['wsdl'] ?? null)) {
            throw new DmsConfigurationException('Automatic DMS actions require a confirmed WSDL URL.');
        }
    }
}
