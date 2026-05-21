<?php

namespace Lalalili\SurveyCore\Listeners;

use Lalalili\SurveyCore\Events\SurveyTokenResolved;

class MarkTokenViewed
{
    public function handle(SurveyTokenResolved $event): void
    {
        if ($event->token->viewed_at !== null) {
            return;
        }

        $event->token->update(['viewed_at' => now()]);
    }
}
