<?php

use Lalalili\SurveyCore\Actions\Triggers\BuildDmsSoapEnvelope;
use Lalalili\SurveyCore\Actions\Triggers\ParseDmsSoapResponse;
use Lalalili\SurveyCore\Actions\Triggers\ValidateDmsActionConfiguration;
use Lalalili\SurveyCore\Enums\DmsExecutionMode;
use Lalalili\SurveyCore\Enums\SurveyTriggerActionAttemptStatus;
use Lalalili\SurveyCore\Exceptions\DmsConfigurationException;

it('builds a SOAP 1.1 envelope from structured parameters and escapes values', function (): void {
    $builder = app(BuildDmsSoapEnvelope::class);
    $xml = $builder->execute('secret<&', [
        'ticketno' => 'SSI20260731000001',
        'description' => '客戶 <不滿意> & 請聯絡',
        'TicketCategory' => [
            ['seq' => 1, 'categorypath' => '電車保母 > 專案'],
        ],
    ]);

    expect($xml)
        ->toContain('http://schemas.xmlsoap.org/soap/envelope/')
        ->toContain('<urn:ws_setTicket')
        ->toContain('<ticketno xsi:type="xsd:string">SSI20260731000001</ticketno>')
        ->toContain('客戶 &lt;不滿意&gt; &amp; 請聯絡')
        ->toContain('<TicketCategory xsi:type="urn:TicketCategory">')
        ->and($builder->redactKey($xml))
        ->toContain('<sKey xsi:type="xsd:string">[REDACTED]</sKey>')
        ->not->toContain('secret&lt;&amp;');
});

it('classifies SOAP success business errors faults and unconfirmed responses', function (): void {
    $parser = app(ParseDmsSoapResponse::class);

    $success = $parser->execute(dmsResponseXml('0', ''), 200, ['0']);
    $businessError = $parser->execute(dmsResponseXml('E101', '車牌不存在'), 200, ['0']);
    $pending = $parser->execute(dmsResponseXml('', ''), 200, []);
    $confirmedEmptySuccess = $parser->execute(dmsResponseXml('', ''), 200, [], true);
    $fault = $parser->execute(
        '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultstring>Server exploded</faultstring></soap:Fault></soap:Body></soap:Envelope>',
        200,
        ['0'],
    );
    $httpError = $parser->execute('<html>bad gateway</html>', 502, ['0']);

    expect($success->status)->toBe(SurveyTriggerActionAttemptStatus::Success)
        ->and($businessError->status)->toBe(SurveyTriggerActionAttemptStatus::BusinessError)
        ->and($businessError->error)->toBe('車牌不存在')
        ->and($pending->status)->toBe(SurveyTriggerActionAttemptStatus::PendingReview)
        ->and($confirmedEmptySuccess->status)->toBe(SurveyTriggerActionAttemptStatus::Success)
        ->and($fault->status)->toBe(SurveyTriggerActionAttemptStatus::SoapFault)
        ->and($fault->error)->toBe('Server exploded')
        ->and($httpError->status)->toBe(SurveyTriggerActionAttemptStatus::HttpError);
});

it('requires production and confirmed parameters for automatic DMS actions', function (): void {
    configureDmsProfiles();
    $validator = app(ValidateDmsActionConfiguration::class);
    $confirmations = array_fill_keys([
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
    ], 'confirmed');

    $validator->execute([
        'profile' => 'production',
        'parameter_confirmations' => $confirmations,
        'open_method_id' => 'I',
        'category_path' => '電車保母 > 專案',
        'employee_code' => 'LC0218',
        'open_question_key' => 'feedback',
        'description_template' => '{{open_answer}}',
    ], DmsExecutionMode::Automatic);

    expect(fn () => $validator->execute([
        'profile' => 'production',
        'parameter_confirmations' => [
            ...$confirmations,
            'response_semantics' => 'tested',
        ],
        'open_method_id' => 'I',
        'category_path' => '電車保母 > 專案',
        'employee_code' => 'LC0218',
        'open_question_key' => 'feedback',
        'description_template' => '{{open_answer}}',
    ], DmsExecutionMode::Automatic))->toThrow(DmsConfigurationException::class)
        ->and(fn () => $validator->execute([
            'profile' => 'qa',
            'parameter_confirmations' => $confirmations,
            'open_method_id' => 'I',
            'category_path' => '電車保母 > 專案',
            'employee_code' => 'LC0218',
            'open_question_key' => 'feedback',
            'description_template' => '{{open_answer}}',
        ], DmsExecutionMode::Automatic))->toThrow(DmsConfigurationException::class);
});

it('allows pending confirmations for manual QA', function (): void {
    configureDmsProfiles();

    app(ValidateDmsActionConfiguration::class)->execute([
        'profile' => 'qa',
        'parameter_confirmations' => ['response_semantics' => 'pending'],
    ], DmsExecutionMode::ManualQa);

    expect(true)->toBeTrue();
});

function dmsResponseXml(string $errorCode, string $errorMessage): string
{
    return <<<XML
    <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
      <soap:Body>
        <ws_setTicketResponse xmlns="urn:ws_CRMTicket">
          <return>
            <error_code>{$errorCode}</error_code>
            <error_msg>{$errorMessage}</error_msg>
          </return>
        </ws_setTicketResponse>
      </soap:Body>
    </soap:Envelope>
    XML;
}

function configureDmsProfiles(): void
{
    config()->set('survey-core.triggers.dms.profiles', [
        'qa' => [
            'endpoint' => 'http://61.57.248.34/lxmqa/bin/webservice/ws_CRMTicket.pwo',
            'wsdl' => 'http://61.57.248.34/lxmqa/bin/webservice/ws_CRMTicket.pwo?wsdl',
            'key' => 'qa-secret',
            'soap_action' => 'urn:ws_CRMTicket#ws_setTicket',
        ],
        'production' => [
            'endpoint' => 'http://61.57.248.35/lxm/bin/webservice/ws_CRMTicket.pwo',
            'wsdl' => 'http://61.57.248.35/lxm/bin/webservice/ws_CRMTicket.pwo?wsdl',
            'key' => 'production-secret',
            'soap_action' => 'urn:ws_CRMTicket#ws_setTicket',
        ],
    ]);
}
