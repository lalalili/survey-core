<?php

namespace Lalalili\SurveyCore\Support;

use Lalalili\SurveyCore\Contracts\DmsEmployeeCodeResolver;
use Lalalili\SurveyCore\Models\SurveyResponse;

final class NullDmsEmployeeCodeResolver implements DmsEmployeeCodeResolver
{
    public function resolve(SurveyResponse $response, array $action): ?string
    {
        return null;
    }
}
