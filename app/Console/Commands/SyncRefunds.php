<?php
namespace App\Console\Commands;
use App\Models\Booking;
use App\Services\{PayMongoService,RefundStatusService};
use Illuminate\Console\Command;
class SyncRefunds extends Command {
 protected $signature='payments:sync-refunds';
 protected $description='Reconcile pending refund status with PayMongo';
 public function handle(PayMongoService $provider,RefundStatusService $refunds):int {
  $count=0;
  Booking::whereIn('refund_status',['pending','processing'])->whereNotNull('paymongo_refund_id')->chunkById(100,function($bookings) use ($provider,$refunds,&$count) {
   foreach($bookings as $booking) { $refund=$provider->getRefund($booking->paymongo_refund_id);if($refund && $refunds->apply($refund)) $count++; }
  });
  $this->info("Reconciled {$count} refunds.");return self::SUCCESS;
 }
}
