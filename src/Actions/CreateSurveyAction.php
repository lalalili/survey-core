<?php

namespace Lalalili\SurveyCore\Actions;

use Illuminate\Support\Facades\DB;
use Lalalili\SurveyCore\Models\Survey;

class CreateSurveyAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Survey
    {
        return DB::transaction(function () use ($data) {
            return Survey::create($data);
        });
    }
}
