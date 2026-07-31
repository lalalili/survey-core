<?php

namespace Lalalili\SurveyCore\Contracts;

use Lalalili\SurveyCore\Models\SurveyResponse;

interface DmsEmployeeCodeResolver
{
    /**
     * @param  array<string, mixed>  $action
     */
    public function resolve(SurveyResponse $response, array $action): ?string;
}
