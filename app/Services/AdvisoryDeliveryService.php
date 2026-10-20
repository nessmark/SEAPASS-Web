<?php
namespace App\Services;
use App\Mail\TravelAdvisoryBroadcastMail;
use App\Models\Passenger;
use App\Models\TravelAdvisory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
class AdvisoryDeliveryService {
 public function broadcast(TravelAdvisory $advisory): string {
  return DB::transaction(function() use ($advisory) {
   $advisory = TravelAdvisory::whereKey($advisory->id)->lockForUpdate()->firstOrFail();
   if (in_array($advisory->push_status,['Queued','Sent','Partial'])) return $advisory->push_status;
   $queued=0; $failed=0;
   $data=$advisory->toArray();
   foreach(Passenger::query()->get() as $passenger) {
    if ($passenger->email) {
     try { Mail::to($passenger->email)->queue(new TravelAdvisoryBroadcastMail($data)); $queued++; } catch(\Throwable $e) { $failed++; }
    }
    if ($passenger->phone) app(SmsService::class)->send($passenger->phone, 'SeaPass advisory: '.$advisory->title.'. '.strip_tags($advisory->message), 'advisory:'.$advisory->id.':passenger:'.$passenger->id);
   }
   $status = $failed ? ($queued ? 'Partial' : 'Failed') : ($queued ? 'Queued' : 'Not Sent');
   $advisory->update(['push_status'=>$status]);
   return $status;
  });
 }
}
