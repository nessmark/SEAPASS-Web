<?php
namespace App\Http\Controllers;
use App\Models\SmsMessage;
use Illuminate\Http\Request;
class TextLinkWebhookController extends Controller {
 public function failed(Request $request) {
  $secret = config('services.textlink.webhook_secret');
  if (!$secret || !is_string($request->input('secret')) || !hash_equals($secret,$request->input('secret'))) return response()->json(['ok'=>false],401);
  $data=$request->validate(['custom_id'=>'required|string|max:30','phone_number'=>'required|string|max:30','textlink_id'=>'nullable|integer']);
  $message=SmsMessage::whereKey($data['custom_id'])->where('to',$data['phone_number'])->first();
  if ($message && $message->status !== 'retry_requested') $message->update(['status'=>'failed','provider_id'=>isset($data['textlink_id'])?(string)$data['textlink_id']:null,'error_message'=>'TextLink reported that this message failed. Check the sending phone and SIM.']);
  return response()->json(['ok'=>true]);
 }
}
