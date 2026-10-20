<?php
namespace App\Services;
use App\Models\{Booking,AuditLog};
use Illuminate\Support\Facades\DB;
class RefundStatusService {
 public function apply(array $refund): bool {
  $attributes=$refund['attributes']??[];
  $status=$attributes['status']??'';
  if (!in_array($status,['pending','processing','succeeded','refunded','failed'])) return false;
  return DB::transaction(function() use ($refund,$attributes,$status) {
   $booking=Booking::where('paymongo_refund_id',$refund['id']??'')->lockForUpdate()->first();
   if (!$booking || (int)round((float)$booking->refund_amount*100)!==(int)($attributes['amount']??0)) return false;
   if (isset($attributes['payment_id']) && $attributes['payment_id']!==$booking->paymongo_payment_id) return false;
   $normalized=in_array($status,['succeeded','refunded'])?'refunded':$status;
   if ($booking->refund_status==='refunded' || $booking->refund_status===$normalized) return true;
   $booking->update(['refund_status'=>$normalized,'payment_review_required'=>$normalized==='failed']);
   AuditLog::record('refund','Refund Status Updated','Refund '.$booking->paymongo_refund_id.' is '.$normalized.' for booking #'.$booking->reference_number);
   return true;
  });
 }
}
