<?php

namespace Lalalili\SurveyCore\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Mail\SurveyResponseReceivedMail;

class SendSurveyResponseNotification implements ShouldQueue
{
    public function handle(SurveySubmitted $event): void
    {
        $recipients = $this->resolveRecipients($event);

        if (empty($recipients)) {
            return;
        }

        $mailable = new SurveyResponseReceivedMail($event->survey, $event->response);

        // Route through email-campaign transactional pipeline for delivery tracking when available
        $transactionalClass = 'Lalalili\\EmailCampaign\\Actions\\SendTransactionalEmailAction';

        if (class_exists($transactionalClass)) {
            app($transactionalClass)->executeWithMailable(
                $recipients,
                $mailable,
                checkSuppression: false,
            );

            return;
        }

        foreach ($recipients as $email) {
            Mail::to($email)->queue($mailable);
        }
    }

    /** @return list<string> */
    private function resolveRecipients(SurveySubmitted $event): array
    {
        $perSurvey = $event->survey->settings()->notifyEmails;

        $global = config('survey-core.notifications.new_response_notify_emails', []);

        $merged = array_unique(array_merge(
            $perSurvey,
            is_array($global) ? $global : [],
        ));

        return array_values(array_filter($merged, fn (mixed $v): bool => is_string($v) && filled($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false));
    }
}
