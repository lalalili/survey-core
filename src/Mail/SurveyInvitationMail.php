<?php

namespace Lalalili\SurveyCore\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Lalalili\SurveyCore\Models\SurveyRecipient;

class SurveyInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly SurveyRecipient $recipient,
        public readonly string $surveyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '邀請您填寫問卷：'.$this->recipient->survey->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'survey-core::mail.invitation',
            with: [
                'recipientName' => $this->recipient->name ?? $this->recipient->email,
                'surveyTitle' => $this->recipient->survey->title,
                'surveyUrl' => $this->surveyUrl,
            ],
        );
    }
}
