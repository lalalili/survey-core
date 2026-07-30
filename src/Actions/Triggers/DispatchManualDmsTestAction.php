<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use Lalalili\SurveyCore\Enums\DmsExecutionMode;
use Lalalili\SurveyCore\Models\SurveyTriggerActionAttempt;
use Lalalili\SurveyCore\Models\SurveyTriggerActionPreset;

final class DispatchManualDmsTestAction
{
    public function __construct(
        private readonly BuildDmsRequestParameters $parameters,
        private readonly DispatchDmsSoapTriggerAction $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $sample
     */
    public function execute(
        SurveyTriggerActionPreset $preset,
        array $sample,
        ?int $initiatedBy = null,
    ): SurveyTriggerActionAttempt {
        $action = $preset->action_json;
        $action['profile'] = 'qa';

        return $this->dispatcher->execute(
            action: $action,
            parameters: $this->parameters->fromManualSample($sample, $action),
            mode: DmsExecutionMode::ManualQa,
            actionKey: 'preset:'.$preset->getKey(),
            presetId: (int) $preset->getKey(),
            initiatedBy: $initiatedBy,
        );
    }
}
