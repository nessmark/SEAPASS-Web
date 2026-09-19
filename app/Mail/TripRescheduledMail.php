<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\TripSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TripRescheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public TripSchedule $newTrip;
    public ?array $oldTripData;
    public string $reason;

    /**
     * Create a new message instance.
     *
     * @param Booking $booking
     * @param TripSchedule $newTrip
     * @param array{date?: string, departure_time?: string, boat_name?: string}|null $oldTripData
     * @param string $reason
     */
    public function __construct(Booking $booking, TripSchedule $newTrip, ?array $oldTripData = null, string $reason = 'Schedule adjustment')
    {
        $this->booking = $booking;
        $this->newTrip = $newTrip;
        $this->oldTripData = $oldTripData;
        $this->reason = $reason;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $ref = $this->booking->reference_number ?? ('SP-' . $this->booking->id);

        return new Envelope(
            subject: "Important: SeaPass Trip Rescheduled - Ticket #{$ref}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.trip_rescheduled',
            with: [
                'booking' => $this->booking,
                'newTrip' => $this->newTrip,
                'oldTripData' => $this->oldTripData,
                'reason' => $this->reason,
            ],
        );
    }
}
