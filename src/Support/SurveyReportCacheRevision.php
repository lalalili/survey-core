<?php

namespace Lalalili\SurveyCore\Support;

use Illuminate\Support\Facades\Cache;

class SurveyReportCacheRevision
{
    public const KEY = 'survey-core:report-cache-revision';

    public function current(): int
    {
        return (int) Cache::get(self::KEY, 0);
    }

    public function bump(): int
    {
        return (int) Cache::increment(self::KEY);
    }
}
