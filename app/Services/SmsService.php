<?php
namespace App\Services;
use App\Models\Booking;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Http;
class SmsService {
 public function configured(): bool { return config('services.textlink.enabled') && filled(config('services.textlink.api_key')); }
 public function send(string $to, string $text, string $deduplicationKey): SmsMessage {
  $to = preg_replace('/[^+0-9]/', '', $to);
  if (preg_match('/^09\d{9}$/', $to)) $to = '+63'.substr($to, 1);
  elseif (preg_match('/^639\d{9}$/', $to)) $to = '+'.$to;
  $message = SmsMessage::firstOrCreate(['deduplication_key' => $deduplicationKey], ['to' => $to, 'body' => $text]);
  if (!$message->wasRecentlyCreated) return $message; // No automatic duplicate sends after uncertain network failures.
  if (!preg_match('/^\+[1-9]\d{7,14}$/', $to)) { $message->update(['status'=>'failed','error_message'=>'Missing or invalid recipient phone number.']); return $message; }
  if (!$this->configured()) { $message->update(['status'=>'not_configured','error_message'=>'SMS is disabled or TextLink credentials are missing.']); return $message; }
  try {
   $payload = ['phone_number' => $to, 'text' => $text, 'custom_id' => (string) $message->id];
   if (config('services.textlink.sim_card_id')) $payload['sim_card_id'] = (int) config('services.textlink.sim_card_id');
   $response = Http::withToken(config('services.textlink.api_key'))->acceptJson()->timeout(15)->post('https://textlinksms.com/api/send-sms', $payload);
   $success = $response->successful() && $response->json('ok') === true;
   $message->update(['status' => $success ? ($response->json('queued') ? 'queued' : 'sent') : 'failed', 'error_message' => $success ? null : 'TextLink did not accept the message. Check device and SIM availability.']);
  } catch (\Throwable $e) { $message->update(['status'=>'unknown','error_message'=>'Provider response was interrupted. Verify provider history before retrying.']); }
  return $message;
 }
 public function bookingUpdate(Booking $booking, string $event): void {
  $text = 'SeaPass '.$booking->reference_number.': '.($booking->status === 'to_be_confirmed' ? 'Payment received. Discount ID verification is pending.' : 'Booking '.$booking->status.'.').' '.$booking->route.' '.$booking->trip_date?->format('M d').' '.$booking->departure_time_slot;
  $this->send($booking->registered_contact_number, $text, 'booking:'.$booking->id.':'.$event);
 }
}
