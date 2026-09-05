<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TravelAdvisoryBroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $advisory;

    /**
     * Create a new message instance.
     */
    public function __construct(array $advisory)
    {
        $this->advisory = $advisory;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $title = $this->advisory['title'] ?? 'Notice';
        $severity = $this->advisory['severity'] ?? 'Advisory';

        return new Envelope(
            subject: "SeaPass Travel Advisory: {$title} ({$severity})",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.travel_advisory',
            with: [
                'advisory' => $this->advisory,
            ],
        );
    }
}
