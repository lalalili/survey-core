<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Lalalili\SurveyCore\Actions\Triggers\BuildDmsRequestParameters;
use Lalalili\SurveyCore\Actions\Triggers\DispatchDmsSoapTriggerAction;
use Lalalili\SurveyCore\Actions\Triggers\DispatchManualDmsTestAction;
use Lalalili\SurveyCore\Actions\Triggers\DmsTicketNumberAllocator;
use Lalalili\SurveyCore\Contracts\DmsCaseRecorder;
use Lalalili\SurveyCore\Contracts\DmsEmployeeCodeResolver;
use Lalalili\SurveyCore\Enums\DmsExecutionMode;
use Lalalili\SurveyCore\Enums\SurveyStatus;
use Lalalili\SurveyCore\Enums\SurveyTriggerActionAttemptStatus;
use Lalalili\SurveyCore\Enums\TriggerDispatchStatus;
use Lalalili\SurveyCore\Exceptions\DmsConfigurationException;
use Lalalili\SurveyCore\Jobs\RunSurveyTriggerJob;
use Lalalili\SurveyCore\Models\DmsTicketSequence;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyResponse;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;
use Lalalili\SurveyCore\Models\SurveyTriggerActionPreset;
use Lalalili\SurveyCore\Models\SurveyTriggerDispatch;
use Lalalili\SurveyCore\Models\SurveyTriggerRule;

beforeEach(function (): void {
    configureDmsExecution();
});

it('sends raw SOAP XML once and stores a redacted encrypted audit attempt', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://dms-qa.test/ws' => Http::response(dmsExecutionResponse('0', ''), 200, [
            'X-DMS-Request-ID' => 'request-1',
        ]),
    ]);
    $preset = dmsExecutionPreset();

    $attempt = app(DispatchManualDmsTestAction::class)->execute($preset, dmsSample());

    expect($attempt->status)->toBe(SurveyTriggerActionAttemptStatus::Success)
        ->and($attempt->request_body)->toContain('[REDACTED]')
        ->not->toContain('qa-secret')
        ->and($attempt->request_parameters['customername'])->toBe('測試 <客戶>')
        ->and($attempt->parsed_response)->toBe(['error_code' => '0', 'error_msg' => ''])
        ->and($attempt->response_http_status)->toBe(200)
        ->and($attempt->getRawOriginal('request_body'))->not->toContain('[REDACTED]')
        ->not->toContain('qa-secret');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Content-Type',
        'text/xml; charset=utf-8',
    )
        && $request->hasHeader('SOAPAction', 'urn:test#ws_setTicket')
        && str_contains($request->body(), '<sKey xsi:type="xsd:string">qa-secret</sKey>')
        && str_contains($request->body(), '測試 &lt;客戶&gt;')
        && str_contains($request->body(), '<description xsi:type="xsd:string">CSI｜請主動聯絡</description>')
        && str_contains($request->body(), '<category xsi:type="urn:ArrayOf_TicketCategory" SOAP-ENC:arrayType="urn:TicketCategory[1]">')
        && str_contains($request->body(), '<item xsi:type="urn:TicketCategory">'));
});

it('records connection errors without an automatic HTTP retry', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://dms-qa.test/ws' => Http::failedConnection('DMS unavailable'),
    ]);

    $attempt = app(DispatchManualDmsTestAction::class)->execute(
        dmsExecutionPreset(),
        dmsSample(),
    );

    expect($attempt->status)->toBe(SurveyTriggerActionAttemptStatus::ConnectionError)
        ->and($attempt->error)->toContain('DMS unavailable');
    Http::assertSentCount(1);
});

it('records a skipped automatic attempt when external communications are disabled', function (): void {
    config()->set('external-communications.enabled', false);
    config()->set('survey-core.triggers.dms.profiles.production.endpoint', null);
    config()->set('survey-core.triggers.dms.profiles.production.key', null);
    Http::preventStrayRequests();
    [$dispatch] = dmsDispatchFixture();
    $action = [
        'type' => 'dms_soap',
        'profile' => 'production',
    ];

    $attempt = app(DispatchDmsSoapTriggerAction::class)->execute(
        action: $action,
        parameters: [],
        mode: DmsExecutionMode::Automatic,
        actionKey: 'preset:99',
        dispatch: $dispatch,
    );

    expect($attempt->status)->toBe(SurveyTriggerActionAttemptStatus::Skipped)
        ->and($attempt->ticket_no)->toBeNull()
        ->and($attempt->endpoint)->toBe('[disabled]')
        ->and($dispatch->refresh()->status)->toBe(TriggerDispatchStatus::Skipped)
        ->and(DmsTicketSequence::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('allocates formatted atomic sequences, detects overflow, and reuses a dispatch ticket', function (): void {
    $allocator = app(DmsTicketNumberAllocator::class);
    $date = Carbon::parse('2026-07-31 12:00:00');

    expect($allocator->execute('qa', 'SSI', $date))->toBe('SSI20260731000001')
        ->and($allocator->execute('qa', 'SSI', $date))->toBe('SSI20260731000002')
        ->and($allocator->execute('production', 'SSI', $date))->toBe('SSI20260731000001');

    [$dispatch] = dmsDispatchFixture();
    SurveyTriggerActionAttempt::create([
        'survey_trigger_dispatch_id' => $dispatch->id,
        'action_key' => 'preset:7',
        'action_type' => 'dms_soap',
        'mode' => 'automatic',
        'profile' => 'production',
        'status' => SurveyTriggerActionAttemptStatus::ConnectionError,
        'ticket_no' => 'CSI20260731000421',
        'endpoint' => 'http://dms.test/ws',
        'request_parameters' => [],
        'request_body' => '<redacted/>',
    ]);

    expect($allocator->execute('production', 'CSI', $date, $dispatch, 'preset:7'))
        ->toBe('CSI20260731000421');

    $allocator->execute('qa', 'IQS', $date);
    DmsTicketSequence::query()
        ->where('profile', 'qa')
        ->where('category', 'IQS')
        ->update(['last_sequence' => 999999]);

    expect(fn () => $allocator->execute('qa', 'IQS', $date))
        ->toThrow(DmsConfigurationException::class);
});

it('uses category-specific open question and description templates and normalizes gender', function (): void {
    [$dispatch, $response] = dmsDispatchFixture('SSI', [
        'gender' => '女性',
        'dlr_code' => 'LC',
        'dept_code' => '09S00',
    ]);
    $response->setRelation('answers', collect());
    $action = [
        ...dmsAutomaticAction(),
        'open_question_keys' => [
            'CSI' => 'csi_feedback',
            'SSI' => 'ssi_feedback',
            'IQS' => 'iqs_feedback',
        ],
        'description_templates' => [
            'SSI' => '銷售滿意度｜{{submitted_at}}｜{{open_answer}}',
        ],
    ];

    $parameters = app(BuildDmsRequestParameters::class)->fromResponse(
        $response,
        $dispatch,
        $action,
        'preset:9',
    );

    expect($parameters['genderid'])->toBe('F')
        ->and($parameters['acb_dealercode'])->toBe('LC')
        ->and($parameters['acb_deptcode'])->toBe('09S00')
        ->and($parameters['description'])->toStartWith('銷售滿意度｜2026-07-31 10:30:00');
});

it('uses the host resolved employee code for automatic DMS requests', function (): void {
    [$dispatch, $response] = dmsDispatchFixture('CSI');
    $response->setRelation('answers', collect());
    app()->instance(DmsEmployeeCodeResolver::class, new class implements DmsEmployeeCodeResolver
    {
        public function resolve(SurveyResponse $response, array $action): ?string
        {
            return '  LC0218  ';
        }
    });

    $parameters = app(BuildDmsRequestParameters::class)->fromResponse(
        $response,
        $dispatch,
        [
            ...dmsAutomaticAction(),
            'employee_code' => 'legacy-fallback',
        ],
        'preset:manager',
    );

    expect($parameters['acb_empcode'])->toBe('LC0218');
});

it('uses category-specific descriptions and the singular fallback for manual samples', function (): void {
    $builder = app(BuildDmsRequestParameters::class);
    $action = [
        'profile' => 'qa',
        'description_template' => 'fallback｜{{survey_category}}｜{{open_answer}}',
        'description_templates' => [
            'CSI' => '服務｜{{survey_category}}｜{{open_answer}}',
            'SSI' => '銷售｜{{survey_category}}｜{{open_answer}}',
            'IQS' => '品質｜{{survey_category}}｜{{open_answer}}',
        ],
    ];

    foreach ([
        'CSI' => '服務｜CSI｜請主動聯絡',
        'SSI' => '銷售｜SSI｜請主動聯絡',
        'IQS' => '品質｜IQS｜請主動聯絡',
    ] as $category => $expectedDescription) {
        $parameters = $builder->fromManualSample([
            ...dmsSample(),
            'ticketno' => "{$category}20260731000001",
            'category' => strtolower($category),
        ], $action);

        expect($parameters['description'])->toBe($expectedDescription);
    }

    unset($action['description_templates']['IQS']);
    $fallback = $builder->fromManualSample([
        ...dmsSample(),
        'ticketno' => 'IQS20260731000002',
        'category' => 'IQS',
    ], $action);

    expect($fallback['description'])->toBe('fallback｜IQS｜請主動聯絡');
});

it('uses category-specific case category paths with a singular fallback', function (): void {
    $builder = app(BuildDmsRequestParameters::class);
    $action = [
        'profile' => 'qa',
        'description_template' => '{{survey_category}}',
        'category_path' => '車主自選 > 其他',
        'category_paths' => [
            'CSI' => '車主自選 > 車輛/零件品質',
            'SSI' => '車主自選 > 銷售服務',
        ],
    ];

    $csi = $builder->fromManualSample([...dmsSample(), 'ticketno' => 'CSI20260731000001'], $action);
    $iqs = $builder->fromManualSample([
        ...dmsSample(),
        'ticketno' => 'IQS20260731000001',
        'category' => 'IQS',
    ], $action);

    expect($csi['TicketCategory'][0]['categorypath'])->toBe('車主自選 > 車輛/零件品質')
        ->and($iqs['TicketCategory'][0]['categorypath'])->toBe('車主自選 > 其他');
});

it('defaults to the documented follow-up ticket type, open method, and category label', function (): void {
    $parameters = app(BuildDmsRequestParameters::class)->fromManualSample(
        [...dmsSample(), 'ticketno' => 'CSI20260731000001'],
        [
            'profile' => 'qa',
            'description_template' => '{{survey_category_label}}',
        ],
    );

    expect($parameters['tickettypeid'])->toBe('CST-FOLLOWUP')
        ->and($parameters['openmethodid'])->toBe('I')
        ->and($parameters['description'])->toBe('服務滿意度回饋');
});

it('records a configuration error attempt and keeps other actions running', function (): void {
    config()->set('external-communications.enabled', true);
    Http::preventStrayRequests();
    Http::fake(['https://hooks.test/other' => Http::response(['ok' => true])]);
    app()->instance(DmsEmployeeCodeResolver::class, new class implements DmsEmployeeCodeResolver
    {
        public function resolve(SurveyResponse $response, array $action): ?string
        {
            throw new DmsConfigurationException('據點「台中」尚未設定服務據點主管及員工編號。');
        }
    });

    $survey = Survey::create([
        'title' => 'DMS survey',
        'status' => SurveyStatus::Published,
        'category' => 'CSI',
    ]);
    $rule = SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => 'DMS rule',
        'is_active' => true,
        'rule_tree_json' => ['op' => 'AND', 'children' => []],
        'actions_json' => [
            dmsAutomaticAction(),
            [
                'type' => 'http_post',
                'endpoint' => 'https://hooks.test/other',
                'payload_template' => ['response_id' => '{{response.id}}'],
            ],
        ],
    ]);
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => '2026-07-31 10:30:00',
        'is_test' => false,
    ]);

    app()->call([new RunSurveyTriggerJob($rule->id, $response->id), 'handle']);

    $attempt = SurveyTriggerActionAttempt::query()->sole();

    expect($attempt->status)->toBe(SurveyTriggerActionAttemptStatus::ConfigurationError)
        ->and($attempt->error)->toContain('尚未設定服務據點主管')
        ->and($attempt->dispatch->status)->toBe(TriggerDispatchStatus::Failed);
    // 其他動作不受 DMS 設定錯誤牽連。
    Http::assertSentCount(1);
});

it('records a configuration error attempt when the action is not fully confirmed', function (): void {
    config()->set('external-communications.enabled', true);
    Http::preventStrayRequests();
    [$dispatch] = dmsDispatchFixture();
    $action = dmsAutomaticAction();
    $action['parameter_confirmations']['response_semantics'] = 'tested';

    $attempt = app(DispatchDmsSoapTriggerAction::class)->execute(
        action: $action,
        parameters: ['ticketno' => 'CSI20260731000001'],
        mode: DmsExecutionMode::Automatic,
        actionKey: 'preset:9',
        dispatch: $dispatch,
    );

    expect($attempt->status)->toBe(SurveyTriggerActionAttemptStatus::ConfigurationError)
        ->and($attempt->error)->toContain('response_semantics')
        ->and($dispatch->refresh()->status)->toBe(TriggerDispatchStatus::Failed);
    Http::assertNothingSent();
});

it('records the DMS case only for successful automatic dispatches', function (): void {
    config()->set('external-communications.enabled', true);
    Http::preventStrayRequests();
    Http::fake([
        'http://dms-production.test/ws' => Http::response(dmsExecutionResponse('0', ''), 200),
        'http://dms-qa.test/ws' => Http::response(dmsExecutionResponse('0', ''), 200),
    ]);
    $recorder = new class implements DmsCaseRecorder
    {
        /** @var list<string> */
        public array $recorded = [];

        public function record(SurveyResponse $response, SurveyTriggerActionAttempt $attempt): void
        {
            $this->recorded[] = (string) $attempt->ticket_no;
        }
    };
    app()->instance(DmsCaseRecorder::class, $recorder);
    [$dispatch] = dmsDispatchFixture();

    app(DispatchDmsSoapTriggerAction::class)->execute(
        action: dmsAutomaticAction(),
        parameters: ['ticketno' => 'CSI20260731000001'],
        mode: DmsExecutionMode::Automatic,
        actionKey: 'preset:9',
        dispatch: $dispatch,
    );
    // QA 手動測試沒有 dispatch，不應寫入案件。
    app(DispatchManualDmsTestAction::class)->execute(dmsExecutionPreset(), dmsSample());

    expect($recorder->recorded)->toBe(['CSI20260731000001']);
});

it('creates an independent dispatch for each action on the same response', function (): void {
    config()->set('external-communications.enabled', true);
    Http::fake([
        'https://hooks.test/first' => Http::response(['ok' => true]),
        'https://hooks.test/second' => Http::response(['ok' => true]),
    ]);
    $survey = Survey::create([
        'title' => 'Multi action survey',
        'status' => SurveyStatus::Published,
    ]);
    $rule = SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => 'Multi action rule',
        'is_active' => true,
        'rule_tree_json' => ['op' => 'AND', 'children' => []],
        'actions_json' => [
            [
                'type' => 'http_post',
                'endpoint' => 'https://hooks.test/first',
                'payload_template' => ['response_id' => '{{response.id}}'],
            ],
            [
                'type' => 'http_post',
                'endpoint' => 'https://hooks.test/second',
                'payload_template' => ['response_id' => '{{response.id}}'],
            ],
        ],
    ]);
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => now(),
        'is_test' => false,
    ]);

    app()->call([new RunSurveyTriggerJob($rule->id, $response->id), 'handle']);

    expect(SurveyTriggerDispatch::query()->where('survey_trigger_rule_id', $rule->id)->count())
        ->toBe(2)
        ->and(SurveyTriggerDispatch::query()->distinct()->count('action_key'))->toBe(2);
    Http::assertSentCount(2);
});

/**
 * @return array{SurveyTriggerDispatch, SurveyResponse}
 */
function dmsDispatchFixture(
    string $category = 'CSI',
    array $payload = [],
): array {
    $survey = Survey::create([
        'title' => 'DMS survey',
        'status' => SurveyStatus::Published,
        'category' => $category,
    ]);
    $rule = SurveyTriggerRule::create([
        'survey_id' => $survey->id,
        'name' => 'DMS rule',
        'is_active' => true,
        'rule_tree_json' => ['op' => 'AND', 'children' => []],
        'actions_json' => [],
    ]);
    $response = SurveyResponse::create([
        'survey_id' => $survey->id,
        'submitted_at' => '2026-07-31 10:30:00',
        'is_test' => false,
    ]);
    $response->setRelation('survey', $survey);
    $response->setRelation('recipient', new SurveyRecipient([
        'name' => '王小明',
        'payload_json' => $payload,
    ]));
    $dispatch = SurveyTriggerDispatch::create([
        'survey_trigger_rule_id' => $rule->id,
        'survey_response_id' => $response->id,
        'action_key' => 'preset:9',
        'status' => TriggerDispatchStatus::Pending,
    ]);

    return [$dispatch, $response];
}

function dmsExecutionPreset(): SurveyTriggerActionPreset
{
    return SurveyTriggerActionPreset::create([
        'key' => 'dms-case',
        'name' => 'DMS case',
        'is_active' => true,
        'action_json' => [
            'type' => 'dms_soap',
            'profile' => 'qa',
            'open_method_id' => 'I',
            'category_path' => '電車保母 > 專案',
            'employee_code' => 'LC0218',
            'description_templates' => [
                'CSI' => '{{survey_category}}｜{{open_answer}}',
            ],
            'success_error_codes' => ['0'],
        ],
    ]);
}

/**
 * @return array<string, mixed>
 */
function dmsSample(): array
{
    return [
        'category' => 'CSI',
        'submitted_at' => '2026-07-31 10:30:00',
        'customername' => '測試 <客戶>',
        'genderid' => '男性',
        'mobilephone' => '0912345678',
        'regono' => 'ABC1234',
        'acb_dealercode' => 'LC',
        'acb_deptcode' => '09S00',
        'open_answer' => '請主動聯絡',
    ];
}

/**
 * @return array<string, mixed>
 */
function dmsAutomaticAction(): array
{
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

    return [
        'type' => 'dms_soap',
        'profile' => 'production',
        'parameter_confirmations' => $confirmations,
        'open_method_id' => 'I',
        'category_path' => '電車保母 > 專案',
        'employee_code' => 'LC0218',
        'open_question_key' => 'feedback',
        'description_template' => '{{survey_category}}｜{{open_answer}}',
        'success_error_codes' => ['0'],
    ];
}

function configureDmsExecution(): void
{
    config()->set('survey-core.triggers.dms.manual_test_enabled', true);
    config()->set('survey-core.triggers.dms.profiles', [
        'qa' => [
            'endpoint' => 'http://dms-qa.test/ws',
            'wsdl' => 'http://dms-qa.test/ws?wsdl',
            'key' => 'qa-secret',
            'soap_action' => 'urn:test#ws_setTicket',
        ],
        'production' => [
            'endpoint' => 'http://dms-production.test/ws',
            'wsdl' => 'http://dms-production.test/ws?wsdl',
            'key' => 'production-secret',
            'soap_action' => 'urn:test#ws_setTicket',
        ],
    ]);
}

function dmsExecutionResponse(string $code, string $message): string
{
    return <<<XML
    <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
      <soap:Body><return><error_code>{$code}</error_code><error_msg>{$message}</error_msg></return></soap:Body>
    </soap:Envelope>
    XML;
}
