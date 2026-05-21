<?php

namespace Lalalili\SurveyCore\Data;

use Lalalili\SurveyCore\Models\SurveyRecipient;
use Lalalili\SurveyCore\Models\SurveyToken;

final readonly class ResolvedToken
{
    /** @param  array<string, mixed>  $payload  Decoded recipient payload_json */
    public function __construct(
        public SurveyToken $token,
        public SurveyRecipient $recipient,
        public array $payload,
    ) {}
}
