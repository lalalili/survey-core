<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Illuminate\Support\Carbon;
use Lalalili\SurveyCore\Contracts\DmsEmployeeCodeResolver;
use Lalalili\SurveyCore\Exceptions\DmsConfigurationException;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;

final class BuildDmsRequestParameters
{
    public function __construct(
        private readonly DmsTicketNumberAllocator $ticketNumbers,
        private readonly DmsEmployeeCodeResolver $employeeCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function fromResponse(
        SurveyResponse $response,
        SurveyTriggerDispatch $dispatch,
        array $action,
        string $actionKey,
    ): array {
        $response->loadMissing(['survey', 'recipient', 'answers.field']);
        $category = strtoupper((string) $response->survey->category);
        $profile = (string) ($action['profile'] ?? config('survey-core.triggers.dms.profile', 'qa'));
        $submittedAt = $response->submitted_at ?? now();
        $recipient = $response->recipient;
        $recipientPayload = $recipient?->payload_json;
        $payload = is_array($recipientPayload)
            ? $recipientPayload
            : [];
        $answerMap = $response->answerMapByFieldKey();
        $openQuestionKey = (string) (
            data_get($action, "open_question_keys.{$category}")
            ?? ($action['open_question_key'] ?? '')
        );
        $action['description_template'] = data_get($action, "description_templates.{$category}")
            ?? ($action['description_template'] ?? '');
        $resolvedEmployeeCode = $this->employeeCodes->resolve($response, $action);

        if (filled($resolvedEmployeeCode)) {
            $action['employee_code'] = trim((string) $resolvedEmployeeCode);
        }

        return $this->parameters(
            action: $action,
            profile: $profile,
            category: $category,
            ticketNo: $this->ticketNumbers->execute($profile, $category, $submittedAt, $dispatch, $actionKey),
            submittedAt: Carbon::instance($submittedAt),
            customerName: (string) ($recipient->name
                ?? $this->firstPayloadValue($payload, ['name', 'username', 'customername'])),
            gender: $this->firstPayloadValue($payload, ['genderid', 'gender']),
            mobile: $this->firstPayloadValue($payload, ['mobile', 'mobilephone']),
            plate: $this->firstPayloadValue($payload, ['regono', 'license_plate']),
            dealerCode: $this->dealerCode($payload, $category),
            departmentCode: $this->departmentCode($payload, $category),
            openAnswer: (string) ($answerMap[$openQuestionKey] ?? ''),
            deliveryDate: $this->firstPayloadValue($payload, ['timedelivered', 'delivery_date']),
        );
    }

    /**
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function fromManualSample(array $sample, array $action): array
    {
        $profile = (string) ($action['profile'] ?? 'qa');
        $category = strtoupper((string) ($sample['category'] ?? ''));
        $submittedAt = Carbon::parse((string) ($sample['submitted_at'] ?? now()));
        $ticketNo = trim((string) ($sample['ticketno'] ?? ''));
        $action['description_template'] = data_get($action, "description_templates.{$category}")
            ?? ($action['description_template'] ?? '');

        if ($ticketNo === '') {
            $ticketNo = $this->ticketNumbers->execute($profile, $category, $submittedAt);
        }

        return $this->parameters(
            action: $action,
            profile: $profile,
            category: $category,
            ticketNo: $ticketNo,
            submittedAt: $submittedAt,
            customerName: (string) ($sample['customername'] ?? ''),
            gender: $sample['genderid'] ?? null,
            mobile: $sample['mobilephone'] ?? null,
            plate: $sample['regono'] ?? null,
            dealerCode: $sample['acb_dealercode'] ?? null,
            departmentCode: $sample['acb_deptcode'] ?? null,
            openAnswer: (string) ($sample['open_answer'] ?? ''),
            deliveryDate: $sample['delivery_date'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function parameters(
        array $action,
        string $profile,
        string $category,
        string $ticketNo,
        Carbon $submittedAt,
        string $customerName,
        mixed $gender,
        mixed $mobile,
        mixed $plate,
        mixed $dealerCode,
        mixed $departmentCode,
        string $openAnswer,
        mixed $deliveryDate,
    ): array {
        $gender = $this->normalizeGender($gender, $action);

        if ($gender !== '' && ! in_array($gender, ['M', 'F', 'O'], true)) {
            throw new DmsConfigurationException('DMS gender must be M, F, or O.');
        }

        $description = $this->description(
            (string) ($action['description_template'] ?? ''),
            [
                'survey_category' => $category,
                'submitted_at' => $submittedAt->format('Y-m-d H:i:s'),
                'delivery_date' => (string) $deliveryDate,
                'open_answer' => $openAnswer,
            ],
        );

        return array_filter([
            'ticketno' => $ticketNo,
            'tickettypeid' => (string) ($action['ticket_type_id'] ?? 'CST-GENERAL'),
            'gradeid' => (string) ($action['grade_id'] ?? 'B'),
            'opendealercode' => (string) ($action['open_dealer_code'] ?? 'LUXGEN'),
            'opendeptcode' => (string) ($action['open_department_code'] ?? 'R0100'),
            'openmethodid' => (string) ($action['open_method_id'] ?? ''),
            'description' => $description,
            'customername' => $customerName,
            'genderid' => $gender,
            'mobilephone' => trim((string) $mobile),
            'regono' => trim((string) $plate),
            'acb_dealercode' => trim((string) $dealerCode),
            'acb_deptcode' => trim((string) $departmentCode),
            'acb_empcode' => trim((string) ($action['employee_code'] ?? '')),
            'datecreated' => $submittedAt->format('Y-m-d H:i:s'),
            'createusercode' => (string) ($action['create_user_code'] ?? 'CSC01'),
            'lastmodified' => $submittedAt->format('Y-m-d H:i:s'),
            'lastmodifiedbycode' => (string) ($action['last_modified_by_code'] ?? 'CSC01'),
            'closebycode' => (string) ($action['close_by_code'] ?? 'CSC01'),
            'TicketCategory' => [[
                'seq' => '1',
                'categorypath' => (string) ($action['category_path'] ?? ''),
            ]],
        ], fn (mixed $value): bool => ! is_string($value) || $value !== '');
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function description(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{'.$key.'}}', $value, $template);
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstPayloadValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (filled($payload[$key] ?? null)) {
                return $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dealerCode(array $payload, string $category): mixed
    {
        return $category === 'SSI'
            ? $this->firstPayloadValue($payload, ['dlr_code', 'dlrcode'])
            : $this->firstPayloadValue($payload, ['dlrcode', 'dlr_code']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function departmentCode(array $payload, string $category): mixed
    {
        return $category === 'SSI'
            ? $this->firstPayloadValue($payload, ['dept_code', 'deptcode'])
            : $this->firstPayloadValue($payload, ['deptcode', 'dept_code']);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function normalizeGender(mixed $gender, array $action): string
    {
        $value = trim((string) $gender);
        $mapping = [
            'M' => 'M',
            'F' => 'F',
            'O' => 'O',
            '男' => 'M',
            '男性' => 'M',
            '女' => 'F',
            '女性' => 'F',
            '法人' => 'O',
            '公司法人' => 'O',
            ...(is_array($action['gender_mapping'] ?? null) ? $action['gender_mapping'] : []),
        ];
        $normalized = strtoupper((string) ($mapping[$value] ?? $value));

        if ($normalized !== '' && ! in_array($normalized, ['M', 'F', 'O'], true)) {
            throw new DmsConfigurationException('DMS gender must map to M, F, or O.');
        }

        return $normalized;
    }
}
