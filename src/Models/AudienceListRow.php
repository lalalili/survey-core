<?php

namespace Lalalili\SurveyCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backwards-compat alias — canonical model lives in lalalili/audience-core.
 * New code should use \Lalalili\AudienceCore\Models\AudienceListRow directly.
 *
 * Adds surveyRecipients() HasMany here (not in audience-core, which must not
 * depend on survey-core).
 *
 * @property-read Collection<int, SurveyRecipient> $surveyRecipients
 */
class AudienceListRow extends \Lalalili\AudienceCore\Models\AudienceListRow
{
    /**
     * @return HasMany<SurveyRecipient, $this>
     */
    public function surveyRecipients(): HasMany
    {
        return $this->hasMany(SurveyRecipient::class);
    }
}
