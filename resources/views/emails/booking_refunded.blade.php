<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>SeaPass Booking Cancelled & Refund Initiated - #{{ $booking->reference_number ?? 'Refunded' }}</title>
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
        }
        body {
            margin: 0;
            padding: 0;
            background-color: #F4F7F6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        @media (prefers-color-scheme: dark) {
            body, table.bg-container {
                background-color: #0F172A !important;
            }
            .email-card {
                background-color: #1E293B !important;
                border-color: #334155 !important;
            }
            .card-header-bg {
                background-color: #1E293B !important;
                border-bottom-color: #334155 !important;
            }
            .title-text {
                color: #F8FAFC !important;
            }
            .subtitle-text {
                color: #94A3B8 !important;
            }
            .ticket-card {
                background-color: #0F172A !important;
                border-color: #334155 !important;
            }
            .ticket-row-border {
                border-bottom-color: #334155 !important;
            }
            .info-label {
                color: #94A3B8 !important;
            }
            .info-value {
                color: #F8FAFC !important;
            }
            .notice-box {
                background-color: #450A0A !important;
                border-left-color: #EF4444 !important;
                color: #FEF2F2 !important;
            }
            .notice-box p {
                color: #FEF2F2 !important;
            }
            .footer-bg {
                background-color: #0F172A !important;
                border-top-color: #334155 !important;
            }
            .footer-text {
                color: #94A3B8 !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F4F7F6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    @php
        $refNumber = $booking->reference_number ?? ('SP-' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT));
        $passengerName = !empty($booking->passenger_name) ? $booking->passenger_name : ($booking->passenger?->name ?? 'Valued Passenger');
        $vesselName = $booking->tripSchedule?->boat?->name ?? 'Commercial Vessel';
        $tripRoute = $booking->route ?? 'Surigao City ⇄ San Jose (Dinagat)';
        $refundFormatted = '₱ ' . number_format((float) $refundAmount, 2);
    @endphp

    <table class="bg-container" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed; background-color: #F4F7F6; padding: 30px 10px;">
        <tr>
            <td align="center">
                <!-- Main Email Card -->
                <table class="email-card" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); border: 1px solid #E2E8F0;">
                    
                    <!-- Header with Logo -->
                    <tr>
                        <td class="card-header-bg" align="center" style="padding: 24px 24px 20px 24px; background-color: #FFFFFF; border-bottom: 1px solid #F1F5F9;">
                            @php
                                $logoPath = public_path('images/Email.jpg');
                                $logoCid = (file_exists($logoPath) && isset($message)) ? $message->embed($logoPath) : null;
                            @endphp
                            <div style="background-color: #FFFFFF; padding: 8px 18px; border-radius: 12px; display: inline-block; border: 1px solid #E2E8F0; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
                                @if($logoCid)
                                    <img src="{{ $logoCid }}" alt="SeaPass Logo" style="max-width: 200px; width: 100%; height: auto; display: block; border: 0; margin: 0 auto;">
                                @else
                                    <h1 style="margin: 0; color: #0F172A; font-size: 22px; font-weight: 800; letter-spacing: 0.5px;">SeaPass</h1>
                                    <p style="margin: 2px 0 0 0; color: #EF4444; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Ticket Verification & Refund Notice</p>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <!-- Hero Banner -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%); padding: 32px 28px; text-align: center; color: #FFFFFF;">
                            <div style="display: inline-block; width: 56px; height: 56px; line-height: 56px; border-radius: 50%; background-color: rgba(255, 255, 255, 0.2); font-size: 26px; margin-bottom: 12px;">
                                ✕
                            </div>
                            <h2 style="margin: 0 0 6px 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #FFFFFF;">
                                Booking Cancelled &amp; Refunded
                            </h2>
                            <p style="margin: 0; font-size: 14px; opacity: 0.95; color: #FEE2E2; font-weight: 500;">
                                Your discounted booking could not be verified. A full refund has been initiated.
                            </p>
                        </td>
                    </tr>

                    <!-- Main Body & Details -->
                    <tr>
                        <td style="padding: 28px 26px 20px 26px;">
                            <p class="subtitle-text" style="margin: 0 0 20px 0; font-size: 15px; color: #475569; line-height: 1.5;">
                                Hello <strong>{{ $passengerName }}</strong>,<br>
                                We regret to inform you that your discounted ticket request for booking <strong>#{{ $refNumber }}</strong> was reviewed and could not be approved by port administration.
                            </p>

                            <!-- Reason Card -->
                            <div class="notice-box" style="background-color: #FEF2F2; border-left: 4px solid #EF4444; border-radius: 8px; padding: 14px 16px; margin-bottom: 22px;">
                                <h4 style="margin: 0 0 6px 0; color: #991B1B; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                                    Verification Rejection Reason
                                </h4>
                                <p style="margin: 0; font-size: 14px; color: #B91C1C; font-weight: 600; line-height: 1.5;">
                                    {{ $reason }}
                                </p>
                            </div>

                            <!-- Refund Breakdown Table -->
                            <table class="ticket-card" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; margin-bottom: 24px;">
                                <tr>
                                    <td class="ticket-row-border" style="padding: 16px 20px; background-color: #F1F5F9; border-bottom: 1px solid #E2E8F0;" colspan="2">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td align="left">
                                                    <span style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.8px;">BOOKING REFERENCE</span><br>
                                                    <span style="font-family: 'Courier New', Courier, monospace; font-size: 18px; font-weight: 800; color: #334155; letter-spacing: 1px;">{{ $refNumber }}</span>
                                                </td>
                                                <td align="right" valign="middle">
                                                    <span style="background-color: #EF4444; color: #FFFFFF; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 5px 12px; border-radius: 9999px; display: inline-block;">
                                                        CANCELLED
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" width="40%">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Route</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" width="60%" align="right">
                                        <span class="info-value" style="font-size: 13px; font-weight: 700; color: #0F172A;">{{ $tripRoute }}</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Payment Method</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" align="right">
                                        <span class="info-value" style="font-size: 13px; font-weight: 700; color: #0284C7;">GCash / QR Ph (PayMongo)</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding: 14px 20px; background-color: #F8FAFC;">
                                        <span class="info-label" style="font-size: 13px; font-weight: 700; color: #334155;">Refunded Amount</span>
                                    </td>
                                    <td style="padding: 14px 20px; background-color: #F8FAFC;" align="right">
                                        <span class="info-value" style="font-size: 18px; font-weight: 800; color: #10B981;">{{ $refundFormatted }}</span>
                                    </td>
                                </tr>
                            </table>

                            <!-- Refund Information Notice -->
                            <div style="background-color: #F0F9FF; border-left: 4px solid #0284C7; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px;">
                                <h4 style="margin: 0 0 6px 0; color: #0369A1; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                                    When Will I Receive My Refund?
                                </h4>
                                <p style="margin: 0; font-size: 13px; color: #0C4A6E; line-height: 1.6;">
                                    PayMongo has initiated the full refund of <strong>{{ $refundFormatted }}</strong> directly back to your GCash / E-Wallet. GCash refunds typically reflect within a few minutes up to 1-3 business days depending on provider processing.
                                </p>
                            </div>

                            <p style="margin: 0 0 10px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                                You may book again at standard regular fare rates or resubmit your discounted booking with a clear and valid Government / Student / Senior ID card.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer-bg" align="center" style="padding: 24px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0;">
                            <p class="footer-text" style="margin: 0 0 6px 0; font-size: 12px; color: #64748B;">
                                Have questions regarding this refund? Contact Port Ticketing at <a href="mailto:support@seapass.ph" style="color: #0D9488; text-decoration: none; font-weight: 600;">support@seapass.ph</a>
                            </p>
                            <p class="footer-text" style="margin: 0; font-size: 11px; color: #94A3B8;">
                                &copy; {{ date('Y') }} SeaPass Port Management System • Surigao City ⇄ San Jose Port
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
