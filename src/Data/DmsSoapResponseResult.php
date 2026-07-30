<?php

namespace Lalalili\SurveyCore\Data;

use Lalalili\SurveyCore\Enums\SurveyTriggerActionAttemptStatus;

final readonly class DmsSoapResponseResult
{
    /**
     * @param  array<string, mixed>  $parsed
     */
    public function __construct(
        public SurveyTriggerActionAttemptStatus $status,
        public array $parsed,
        public ?string $error,
    ) {}
}
