<?php
namespace App\Http\Controllers;
use App\Models\SmsMessage;
use App\Services\SmsService;
use Illuminate\Http\Request;
class SmsController extends Controller {
 public function retry(Request $request, SmsMessage $smsMessage, SmsService $sms) {
  $request->validate(['confirm_not_sent'=>'required|accepted']);
  if (!$sms->configured()) return response()->json(['message'=>'Configure and enable TextLink before retrying.'],422);
  return \Illuminate\Support\Facades\DB::transaction(function() use ($smsMessage, $sms) {
   $message=SmsMessage::whereKey($smsMessage->id)->lockForUpdate()->firstOrFail();
   if (!in_array($message->status,['failed','not_configured','unknown'])) return response()->json(['message'=>'This message is already queued, sent or retried.'],422);
   $new=$sms->send($message->to,$message->body,'retry:'.$message->id);
   $message->update(['status'=>'retry_requested','error_message'=>'Operator verified this message was not sent and requested retry #'.$new->id]);
   return response()->json(['message'=>'Retry recorded. Check the new attempt status.','sms'=>['id'=>$new->id,'status'=>$new->status]]);
  });
 }
 public function index(Request $request, SmsService $sms) {
  $data = ['smsConfigured' => (bool) $sms->configured(), 'smsProvider' => 'TextLink', 'smsMessages' => SmsMessage::latest()->paginate(25)->through(fn($m) => ['id'=>$m->id,'to'=>$m->masked_to,'status'=>$m->status,'created_at'=>$m->created_at,'error_message'=>$m->error_message])];
  return $request->expectsJson() || $request->query('format') === 'json' ? response()->json($data) : view('admin.sms_dashboard', $data);
 }
}
