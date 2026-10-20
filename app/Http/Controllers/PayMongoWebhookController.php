<?php
namespace App\Http\Controllers;
use App\Models\Booking;
use App\Services\PaymentCaptureService;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
class PayMongoWebhookController extends Controller
{
 public function handle(Request $request, PayMongoService $provider, PaymentCaptureService $capture) {
  if (!$provider->verifyWebhookSignature($request->getContent(), $request->header('Paymongo-Signature'))) return response()->json(['error' => 'Invalid signature.'], 400);
  $event = $request->input('data.attributes.type');
  if (in_array($event, ['refund.succeeded','payment.refund.updated','payment.refunded'])) {
   $resource=$request->input('data.attributes.data',[]);
   $refunds=($resource['type']??'')==='refund' ? [$resource] : ($resource['attributes']['refunds']??[]);
   foreach($refunds as $refund) app(\App\Services\RefundStatusService::class)->apply($refund);
   return response()->json(['status'=>'ok']);
  }
  if ($event !== 'checkout_session.payment.paid') return response()->json(['status' => 'ignored']);
  $session = $request->input('data.attributes.data', []);
  $payment = $session['attributes']['payments'][0] ?? [];
  $attributes = $payment['attributes'] ?? [];
  $booking = Booking::where('paymongo_checkout_session_id', $session['id'] ?? '')->first();
  if (!$booking) return response()->json(['status' => 'ignored', 'message' => 'Unknown checkout session.']);
  if (($attributes['status'] ?? '') !== 'paid') return response()->json(['error' => 'Payment is not captured.'], 422);
  $capture->capture($booking, $session['id'], $payment['id'] ?? '', (int) ($attributes['amount'] ?? 0), $attributes['currency'] ?? '', $session['attributes']['payment_intent']['id'] ?? $attributes['payment_intent_id'] ?? null);
  return response()->json(['status' => 'ok']);
 }
}
