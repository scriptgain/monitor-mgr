<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain-text incident mail.
 *
 * Text rather than HTML on purpose: these are read on a phone at 3am, they get
 * forwarded into chat, and the body is already the same string every other
 * channel receives, so there is nothing an HTML layout would add.
 */
class IncidentAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $bodyText) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.incident', with: ['bodyText' => $this->bodyText]);
    }
}
