<?php

namespace Lalalili\SurveyCore\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyResponse;

class SurveyResponseReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Survey $survey,
        public readonly SurveyResponse $response,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【新回應】'.$this->survey->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'survey-core::mail.response-received',
            with: [
                'surveyTitle' => $this->survey->title,
                'responseId' => $this->response->id,
                'responseNumber' => $this->response->response_number,
                'submittedAt' => $this->response->submitted_at?->format('Y-m-d H:i'),
                'recipientName' => $this->response->recipient?->name,
                'recipientEmail' => $this->response->recipient?->email,
                'collectorName' => $this->response->collector?->name,
            ],
        );
    }
}
