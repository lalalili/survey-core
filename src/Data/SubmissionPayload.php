<?php

namespace Lalalili\SurveyCore\Data;

final readonly class SubmissionPayload
{
    /**
     * @param  array<string, mixed>  $visibleAnswers  Keyed by field_key, from frontend
     * @param  ResolvedToken|null  $tokenContext  Resolved token context, if token was provided
     */
    public function __construct(
        public array $visibleAnswers,
        public ?ResolvedToken $tokenContext = null,
    ) {}
}
