<?php

namespace Lalalili\SurveyCore\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Lalalili\SurveyCore\Events\SurveySubmitted;
use Lalalili\SurveyCore\Mail\SurveyResponseReceivedMail;
use Lalalili\SurveyCore\Support\EmailCampaignIntegration;

class SendSurveyResponseNotification implements ShouldQueue
{
    public function handle(SurveySubmitted $event): void
    {
        $recipients = $this->resolveRecipients($event);

        if (empty($recipients)) {
            return;
        }

        $mailable = new SurveyResponseReceivedMail($event->survey, $event->response);

        $transactionalClass = EmailCampaignIntegration::transactionalEmailActionClass();

        if (EmailCampaignIntegration::enabled() && class_exists($transactionalClass)) {
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
