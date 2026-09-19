<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingRefundedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public string $reason;
    public float $refundAmount;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, string $reason = '', ?float $refundAmount = null)
    {
        $this->booking = $booking;
        $this->reason = $reason ?: ($booking->rejection_reason ?: 'Discount ID verification could not be verified.');
        $this->refundAmount = $refundAmount ?? ((float) ($booking->refund_amount ?: $booking->amount_collected));
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $refNumber = $this->booking->reference_number ?? ('SP-' . $this->booking->id);

        return new Envelope(
            subject: "Booking Cancelled & Refund Initiated - SeaPass #{$refNumber}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.booking_refunded',
            with: [
                'booking' => $this->booking,
                'reason' => $this->reason,
                'refundAmount' => $this->refundAmount,
            ],
        );
    }
}
