<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>PayMongo Checkout - GCash / QR Ph</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        body {
            background-color: #0b1329;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .checkout-card {
            background: #131c36;
            width: 100%;
            max-width: 420px;
            border-radius: 20px;
            border: 1px solid #233157;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #0056b3 0%, #007bff 100%);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-icon {
            background: #ffffff;
            color: #007bff;
            font-weight: 900;
            font-size: 18px;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .brand-text h1 {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.5px;
        }
        .brand-text p {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.8);
        }
        .badge {
            background: rgba(255, 255, 255, 0.2);
            font-size: 10px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .content {
            padding: 24px;
            text-align: center;
        }
        .amount-container {
            margin-bottom: 20px;
        }
        .amount-label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 4px;
        }
        .amount-value {
            font-size: 36px;
            font-weight: 800;
            color: #38bdf8;
        }
        .trip-info {
            background: #0d1527;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
            text-align: left;
            border: 1px solid #1e293b;
        }
        .trip-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .trip-info-row:last-child {
            margin-bottom: 0;
        }
        .trip-info-label {
            color: #94a3b8;
        }
        .trip-info-val {
            font-weight: 600;
            color: #e2e8f0;
        }
        .qr-wrapper {
            background: #ffffff;
            padding: 16px;
            border-radius: 16px;
            display: inline-block;
            margin-bottom: 16px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }
        .qr-wrapper img {
            display: block;
            width: 210px;
            height: 210px;
        }
        .scan-instruction {
            font-size: 13px;
            color: #cbd5e1;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-pay {
            display: block;
            width: 100%;
            background: #007bff;
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 15px;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.4);
            margin-bottom: 12px;
        }
        .btn-pay:hover {
            background: #0069d9;
        }
        .btn-pay:active {
            transform: scale(0.98);
        }
        .btn-cancel {
            display: block;
            width: 100%;
            background: transparent;
            color: #94a3b8;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-cancel:hover {
            background: #1e293b;
            color: #ffffff;
        }
        .footer-note {
            font-size: 11px;
            color: #64748b;
            margin-top: 16px;
            line-height: 1.4;
        }
    </style>
</head>
<body>

    <div class="checkout-card">
        <div class="header">
            <div class="brand">
                <div class="brand-icon">P</div>
                <div class="brand-text">
                    <h1>PayMongo Checkout</h1>
                    <p>SeaPass Ferry Port Portal</p>
                </div>
            </div>
            <div class="badge">GCash / QR Ph</div>
        </div>

        <div class="content">
            <div class="amount-container">
                <div class="amount-label">Amount to Pay</div>
                <div class="amount-value">₱{{ number_format((float) $booking->amount_collected, 2) }}</div>
            </div>

            <div class="trip-info">
                <div class="trip-info-row">
                    <span class="trip-info-label">Reference:</span>
                    <span class="trip-info-val">#{{ $booking->reference_number }}</span>
                </div>
                <div class="trip-info-row">
                    <span class="trip-info-label">Passenger:</span>
                    <span class="trip-info-val">{{ $booking->passenger_name }}</span>
                </div>
                <div class="trip-info-row">
                    <span class="trip-info-label">Route:</span>
                    <span class="trip-info-val">{{ $booking->route }}</span>
                </div>
                <div class="trip-info-row">
                    <span class="trip-info-label">Schedule:</span>
                    <span class="trip-info-val">{{ optional($booking->trip_date)->format('M d, Y') }} · {{ $booking->departure_time_slot }}</span>
                </div>
            </div>

            <!-- Dynamic QR Code (Simulated QR Ph / GCash Code) -->
            <div class="qr-wrapper">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data={{ urlencode('PAYMONGO_QRPH_DEMO_' . $booking->reference_number . '_' . $booking->amount_collected) }}" alt="Dynamic QR Code" />
            </div>

            <div class="scan-instruction">
                <span>📱</span>
                <span>Scan this QR code using <strong>GCash</strong> or any <strong>QR Ph</strong> banking app</span>
            </div>

            <!-- Simulated Payment Form -->
            <form action="{{ url('/payment/mock-checkout/pay') }}" method="POST">
                @csrf
                <input type="hidden" name="ref" value="{{ $booking->reference_number }}">
                <input type="hidden" name="session_id" value="{{ $sessionId }}">
                
                <button type="submit" class="btn-pay">
                    CONFIRM & SIMULATE GCASH PAYMENT
                </button>
            </form>

            <a href="{{ url('/payment/cancel?ref=' . $booking->reference_number) }}" class="btn-cancel">
                Cancel Transaction
            </a>

            <div class="footer-note">
                🔒 Protected by PayMongo 256-bit encryption.<br>
                For testing/demo: Clicking confirm simulates instant GCash payment authorization.
            </div>
        </div>
    </div>

</body>
</html>
