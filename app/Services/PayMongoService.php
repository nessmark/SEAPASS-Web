<?php
namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PayMongoService
{
    public function isMockEnvironment(): bool
    {
        return (bool) config('services.paymongo.mock', false) && app()->environment(['local', 'testing']);
    }

    private function client()
    {
        $key = config('services.paymongo.secret_key');
        if (!$key || $key === 'sk_test_sample') throw new RuntimeException('PayMongo has not been configured.');
        return Http::withBasicAuth($key, '')->acceptJson()->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim(config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/').'/'.$path;
    }

    public function createCheckoutSession(Booking $booking, array $options = []): array
    {
        $booking->ensureReferenceNumber();
        $amount = (int) round((float) $booking->amount_collected * 100);
        if ($amount <= 0) throw new RuntimeException('A positive server-calculated fare is required.');
        $appUrl = rtrim(config('app.url'), '/');
        if ($this->isMockEnvironment()) {
            $id = $booking->paymongo_checkout_session_id ?: 'cs_mock_'.Str::random(24);
            $intent = 'pi_mock_'.Str::random(24);
            $url = $appUrl.'/payment/mock-checkout?'.http_build_query(['session_id' => $id, 'ref' => $booking->reference_number]);
        } else {
            // Reuse an existing checkout; repeated taps must not create competing payments.
            if ($booking->paymongo_checkout_session_id) {
                $existing = $this->getCheckoutSession($booking->paymongo_checkout_session_id);
                if (!$existing) throw new RuntimeException('Unable to retrieve the existing payment session. Try again later.');
                $id = $existing['id'];
                $intent = $existing['attributes']['payment_intent']['id'] ?? null;
                $url = $existing['attributes']['checkout_url'] ?? null;
            } else {
                $response = $this->client()->post($this->url('checkout_sessions'), ['data' => ['attributes' => [
                    'billing' => array_filter(['name' => $booking->passenger_name, 'email' => $booking->email, 'phone' => $booking->contact_number]),
                    'send_email_receipt' => true, 'show_description' => true, 'show_line_items' => true,
                    'description' => 'SeaPass booking '.$booking->reference_number,
                    'line_items' => [['amount' => $amount, 'currency' => 'PHP', 'name' => 'SeaPass Ferry Ticket - '.$booking->route, 'quantity' => 1]],
                    'payment_method_types' => ['gcash', 'qrph', 'paymaya', 'card'],
                    'success_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('payment.success', now()->addHours(2), ['ref'=>$booking->reference_number]),
                    'cancel_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('payment.cancel', now()->addHours(2), ['ref'=>$booking->reference_number]),
                    'metadata' => ['booking_id' => (string) $booking->id, 'reference_number' => $booking->reference_number],
                ]]]);
                if (!$response->successful()) throw new RuntimeException('The payment provider could not open checkout. Please retry.');
                $id = $response->json('data.id');
                $url = $response->json('data.attributes.checkout_url');
                $intent = $response->json('data.attributes.payment_intent.id');
            }
            if (!$id || !$url || !str_starts_with($url, 'https://')) throw new RuntimeException('Invalid checkout response from payment provider.');
        }
        $booking->update(['paymongo_checkout_session_id' => $id, 'paymongo_payment_intent_id' => $intent]);
        return ['checkout_url' => $url, 'checkout_session_id' => $id, 'payment_intent_id' => $intent, 'mock' => $this->isMockEnvironment(), 'raw' => []];
    }

    public function refundPayment(string $paymentIdOrIntentId, float $amount, string $reason = 'requested_by_customer', ?string $notes = null): array
    {
        if ($amount <= 0) return ['success' => false, 'refund_id' => null, 'status' => 'failed', 'amount' => $amount, 'raw' => []];
        if ($this->isMockEnvironment() && preg_match('/^(pay|pi)_mock_/', $paymentIdOrIntentId)) {
            return ['success' => true, 'refund_id' => 'ref_mock_'.Str::random(24), 'status' => 'refunded', 'amount' => $amount, 'raw' => []];
        }
        $failureStatus = 'unknown';
        try {
            $paymentId = $paymentIdOrIntentId;
            if (str_starts_with($paymentId, 'pi_')) {
                $intent = $this->client()->get($this->url('payment_intents/'.$paymentId));
                $paymentId = $intent->json('data.attributes.payments.0.id');
            }
            if (!$paymentId || !str_starts_with($paymentId, 'pay_') || str_contains($paymentId, '_mock_')) throw new RuntimeException('No captured payment is available for refund.');
            $response = $this->client()->post($this->url('refunds'), ['data' => ['attributes' => [
                'amount' => (int) round($amount * 100), 'payment_id' => $paymentId,
                'reason' => in_array($reason, ['requested_by_customer', 'duplicate', 'fraudulent']) ? $reason : 'requested_by_customer',
                'notes' => $notes ?: 'SeaPass booking refund',
            ]]]);
            if ($response->clientError()) $failureStatus='failed';
            $id = $response->json('data.id');
            $status = $response->json('data.attributes.status');
            if (!$response->successful() || !$id || !in_array($status, ['pending', 'processing', 'succeeded', 'refunded'])) throw new RuntimeException('Refund was not accepted.');
            return ['success' => true, 'refund_id' => $id, 'status' => $status === 'succeeded' ? 'refunded' : $status, 'amount' => $amount, 'raw' => []];
        } catch (\Throwable $e) {
            Log::warning('PayMongo refund not completed.', ['payment_id' => $paymentIdOrIntentId]);
            return ['success' => false, 'refund_id' => null, 'status' => $failureStatus, 'amount' => $amount, 'raw' => []];
        }
    }

    public function getCheckoutSession(string $sessionId): ?array
    {
        if ($this->isMockEnvironment() || str_contains($sessionId, '_mock_')) return null;
        try {
            $response = $this->client()->get($this->url('checkout_sessions/'.$sessionId));
            return $response->successful() ? $response->json('data') : null;
        } catch (\Throwable $e) { return null; }
    }

    public function getRefund(string $id): ?array
    {
        if ($this->isMockEnvironment() || str_contains($id,'_mock_')) return null;
        try {
            $response=$this->client()->get($this->url('refunds/'.$id));
            return $response->successful() ? $response->json('data') : null;
        } catch (\Throwable $e) { return null; }
    }

    public function verifyWebhookSignature(string $payload, ?string $signatureHeader): bool
    {
        $secret = config('services.paymongo.webhook_secret');
        if (!$secret || !$signatureHeader) return false;
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }
        $timestamp = $parts['t'] ?? '';
        $mode = str_starts_with((string) config('services.paymongo.secret_key'), 'sk_live_') ? 'li' : 'te';
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || empty($parts[$mode])) return false;
        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $parts[$mode]);
    }
}
