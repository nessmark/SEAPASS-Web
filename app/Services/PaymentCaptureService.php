<?php
namespace App\Services;
use App\Mail\BookingConfirmationMail;
use App\Models\AuditLog;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PaymentCaptureService
{
    public function capture(Booking $booking, string $sessionId, string $paymentId, int $amount, string $currency, ?string $intentId = null): Booking
    {
        $changed = false;
        $booking = DB::transaction(function () use ($booking, $sessionId, $paymentId, $amount, $currency, $intentId, &$changed) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($sessionId !== $booking->paymongo_checkout_session_id || $amount !== (int) round((float) $booking->amount_collected * 100) || strtoupper($currency) !== 'PHP' || !str_starts_with($paymentId, 'pay_')) {
                throw ValidationException::withMessages(['payment' => 'Payment does not match the reserved checkout, amount and currency.']);
            }
            if ($booking->payment_received_at || $booking->paymongo_payment_id) return $booking; // Idempotent across event IDs and delayed retries.
            if (Booking::where('paymongo_payment_id', $paymentId)->where('id', '!=', $booking->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'Payment has already been assigned.']);
            }
            $late = $booking->status !== 'pending' || ($booking->reservation_expires_at && $booking->reservation_expires_at->isPast());
            if ($late && $booking->status === 'pending') {
                app(BookingService::class)->cancelBooking($booking, 'Payment arrived after the reservation deadline. Refund review required.');
                $booking->refresh();
            }
            $booking->update([
                'paymongo_payment_id' => $paymentId, 'paymongo_payment_intent_id' => $intentId ?: $booking->paymongo_payment_intent_id,
                'payment_received_at' => now(), 'payment_review_required' => $late,
                'status' => $late ? $booking->status : ($booking->hasDiscounts() ? 'to_be_confirmed' : 'confirmed'),
            ]);
            if (!$late && !$booking->hasDiscounts()) $booking->generateQrCode();
            AuditLog::record('booking', $late ? 'Late Payment Requires Review' : 'Payment Captured', 'Payment recorded for booking #'.$booking->reference_number.'.'.($late ? ' Reservation is unavailable; manual refund review required.' : ''));
            $changed = true;
            return $booking;
        });
        if ($changed) {
            if ($booking->status === 'confirmed' && $booking->email) {
                try { Mail::to($booking->email)->queue(new BookingConfirmationMail($booking)); } catch (\Throwable $e) { report($e); }
            }
            if ($booking->passenger_id) app(FcmPushService::class)->sendToPassenger($booking->passenger_id, 'SeaPass payment received', $booking->payment_review_required ? 'Your payment needs port-office review because the reservation is no longer available.' : ($booking->status === 'confirmed' ? 'Your booking is confirmed. Your boarding pass is ready.' : 'Your discount ID is awaiting verification.'), ['booking_id'=>(string)$booking->id,'status'=>$booking->status]);
            if (!$booking->payment_review_required) app(SmsService::class)->bookingUpdate($booking, 'payment');
        }
        return $booking;
    }
}
