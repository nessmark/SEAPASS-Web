<?php
namespace App\Console\Commands;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class ExpireReservations extends Command {
 protected $signature = 'bookings:expire';
 protected $description = 'Release unpaid reservations past their payment deadline';
 public function handle(BookingService $service): int {
  $count = 0;
  Booking::whereRaw("LOWER(status) = 'pending'")->whereNull('payment_received_at')->where('reservation_expires_at','<=',now())->chunkById(100,function($bookings) use ($service, &$count) {
   foreach($bookings as $booking) DB::transaction(function() use ($booking, $service, &$count) {
    $booking = Booking::whereKey($booking->id)->lockForUpdate()->first();
    if ($booking->status !== 'pending' || $booking->payment_received_at) return;
    $service->cancelBooking($booking, 'Payment reservation expired.');
    $booking->refresh()->update(['status'=>'expired']); $count++;
   });
  });
  $this->info("Expired {$count} unpaid reservations."); return self::SUCCESS;
 }
}
