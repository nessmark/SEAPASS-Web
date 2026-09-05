<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>SeaPass Travel Advisory - {{ $advisory['title'] ?? 'Notice' }}</title>
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
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
            .info-card-bg {
                background-color: #0F172A !important;
                border-color: #334155 !important;
            }
            .info-label {
                color: #94A3B8 !important;
            }
            .info-value {
                color: #F8FAFC !important;
            }
            .route-highlight {
                color: #38BDF8 !important;
            }
            .message-box {
                background-color: #064E3B !important;
                border-left-color: #10B981 !important;
                color: #ECFDF5 !important;
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
    <table class="bg-container" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed; background-color: #F4F7F6; padding: 30px 10px;">
        <tr>
            <td align="center">
                <!-- Email Container Card -->
                <table class="email-card" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08); border: 1px solid #E2E8F0;">
                    
                    <!-- Header with Logo -->
                    <tr>
                        <td class="card-header-bg" align="center" style="padding: 28px 24px 22px 24px; background-color: #FFFFFF; border-bottom: 1px solid #F1F5F9;">
                            @php
                                $logoPath = public_path('images/Email.jpg');
                                $logoCid = (file_exists($logoPath) && isset($message)) ? $message->embed($logoPath) : null;
                            @endphp
                            <div style="background-color: #FFFFFF; padding: 10px 20px; border-radius: 12px; display: inline-block; border: 1px solid #E2E8F0; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
                                @if($logoCid)
                                    <img src="{{ $logoCid }}" alt="SeaPass Logo" style="max-width: 220px; width: 100%; height: auto; display: block; border: 0; margin: 0 auto;">
                                @else
                                    <h1 style="margin: 0; color: #0F172A; font-size: 24px; font-weight: 800; letter-spacing: 0.5px;">SeaPass</h1>
                                    <p style="margin: 2px 0 0 0; color: #0D9488; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Surigao - San Jose (Dinagat) Port</p>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 30px 28px 24px 28px; text-align: left;">
                            <!-- Severity Pill Badge -->
                            <div style="text-align: center; margin-bottom: 18px;">
                                @php
                                    $severity = strtolower($advisory['severity'] ?? 'information');
                                    $badgeBg = '#0284C7';
                                    if (in_array($severity, ['critical', 'warning'])) {
                                        $badgeBg = '#DC2626';
                                    } elseif ($severity === 'advisory') {
                                        $badgeBg = '#D97706';
                                    }
                                @endphp
                                <span style="background-color: {{ $badgeBg }}; color: #FFFFFF !important; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; padding: 6px 16px; border-radius: 99px; display: inline-block;">
                                    📣 {{ $advisory['severity'] ?? 'Information' }} • TRAVEL ADVISORY
                                </span>
                                
                                <h2 class="title-text" style="margin: 14px 0 0 0; color: #0F172A; font-size: 22px; font-weight: 800; line-height: 1.3;">
                                    {{ $advisory['title'] ?? 'Port Advisory Notice' }}
                                </h2>
                            </div>

                            <!-- Attribute Details Grid Box -->
                            <table class="info-card-bg" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 18px; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 6px 0; vertical-align: top; width: 35%;">
                                        <span class="info-label" style="display: block; font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px;">Advisory Type</span>
                                        <span class="info-value" style="display: block; font-size: 14px; font-weight: 700; color: #0F172A; margin-top: 2px;">{{ $advisory['type'] ?? 'General Advisory' }}</span>
                                    </td>
                                    <td style="padding: 6px 0; vertical-align: top; width: 65%;">
                                        <span class="info-label" style="display: block; font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px;">Affected Route</span>
                                        <span class="info-value route-highlight" style="display: block; font-size: 14px; font-weight: 700; color: #0284C7; margin-top: 2px;">{{ $advisory['route'] ?? 'All Routes' }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="padding-top: 12px; border-top: 1px dashed #CBD5E1;">
                                        <span class="info-label" style="display: block; font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px;">Effective Period</span>
                                        <span class="info-value" style="display: block; font-size: 14px; font-weight: 600; color: #0F172A; margin-top: 2px;">
                                            {{ !empty($advisory['effectiveFrom']) ? $advisory['effectiveFrom'] : 'Immediate' }}
                                            @if(!empty($advisory['until']))
                                                until {{ $advisory['until'] }}
                                            @else
                                                (Until further notice)
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            <!-- Message Content Callout Box -->
                            <div style="margin-bottom: 22px;">
                                <span class="info-label" style="display: block; font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Advisory Details & Instructions</span>
                                <div class="message-box" style="background-color: #F0FDF4; border-left: 4px solid #10B981; border-radius: 10px; padding: 18px; color: #065F46; font-size: 14px; line-height: 1.6; white-space: pre-wrap; font-weight: 500;">{{ $advisory['message'] ?? 'Please stay tuned for official sea transport schedule changes.' }}</div>
                            </div>

                            <p class="footer-text" style="margin: 0; color: #64748B; font-size: 13px; line-height: 1.5; text-align: center;">
                                ⛵ Please adjust your travel plans accordingly. For live boat departure updates, check your <strong>SeaPass Mobile App</strong>.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer-bg" style="padding: 20px 24px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0; text-align: center;">
                            <p class="footer-text" style="margin: 0 0 4px 0; color: #475569; font-size: 13px; font-weight: 700;">
                                SeaPass Port Passenger System
                            </p>
                            <p class="footer-text" style="margin: 0; color: #94A3B8; font-size: 12px;">
                                Surigao – San Jose (Dinagat) Port • Automatic System Notification
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
