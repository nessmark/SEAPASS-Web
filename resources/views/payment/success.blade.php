@php
    $status = strtolower($booking?->status ?? '');
    $paid = in_array($status, ['confirmed', 'completed', 'approved', 'to_be_confirmed'], true);
    $review = $status === 'to_be_confirmed';
    $cancelled = in_array($status, ['cancelled', 'refunded', 'failed'], true);
    $heading = $paid ? 'Payment received' : ($cancelled ? 'Check your booking' : 'Waiting for payment confirmation');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }} | SeaPass</title>
    @include('partials.head-icons')
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <style>
        *{box-sizing:border-box;margin:0}body{font-family:var(--font-sans);background:var(--canvas);color:var(--text);min-height:100dvh;display:grid;place-items:center;padding:24px}.payment-card{background:var(--surface);border-radius:24px;padding:32px 28px;width:100%;max-width:390px}.payment-brand{color:var(--accent-strong);font-size:14px;font-weight:600;margin-bottom:32px}.payment-mark{width:48px;height:48px;border-radius:50%;display:grid;place-items:center;color:var(--accent-strong);background:var(--accent-tint);margin-bottom:20px}.payment-mark svg{width:26px;height:26px}h1{font-size:26px;font-weight:600;letter-spacing:-.03em;line-height:1.2}p{color:var(--text-2);font-size:14px;line-height:1.65;margin-top:16px}.payment-ref{margin:24px 0;padding:16px 0;border-block:1px solid var(--hairline);color:var(--text-2);font-size:12px}.payment-ref strong{display:block;margin-top:6px;color:var(--text);font-size:15px;font-variant-numeric:tabular-nums}.payment-button{display:block;text-align:center;padding:14px;border-radius:12px;background:var(--accent);color:var(--on-accent);text-decoration:none;font-size:14px;font-weight:600;border:0;width:100%;cursor:pointer}.payment-return{font-size:12px;margin-top:20px}.payment-button:hover{background:var(--accent-strong)}
    </style>
</head>
<body>
    <main class="payment-card">
        <div class="payment-brand">SeaPass</div>
        <div class="payment-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">@if($paid)<path d="m5 12 4 4L19 6"/>@else<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@endif</svg></div>
        <h1>{{ $heading }}</h1>
        <p>{{ $paid ? ($review ? 'Your payment is confirmed. Port staff still need to verify the discount IDs before issuing your boarding QR.' : 'Your payment is confirmed. Open your booking in SeaPass to see your ticket and boarding QR.') : ($cancelled ? 'This booking is marked '.str_replace('_', ' ', $status).'. Open SeaPass to review the latest booking details.' : 'SeaPass has not received verified payment confirmation yet. It can take a moment after checkout. Please check again before making another payment.') }}</p>
        @if($ref)<div class="payment-ref">Booking reference<strong>{{ $ref }}</strong></div>@endif
        @if(!$paid && !$cancelled)<a class="payment-button" href="{{ request()->fullUrl() }}">Check payment status</a>@endif
        <button type="button" id="closePayment" class="payment-button" style="display:none" onclick="window.close()">Return to SeaPass</button>
        <p class="payment-return">You can return to the SeaPass app and open My bookings to check this booking.</p>
    </main>
    <script>if(window.opener){document.getElementById('closePayment').style.display='block';}</script>
</body>
</html>
