<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>SeaPass Schedule Update</title>
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
            .alert-banner {
                background-color: #7C2D12 !important;
                border-left-color: #EA580C !important;
                color: #FFEDD5 !important;
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
<body style="margin: 0; padding: 0; background-color: #F8FAFC; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table class="bg-container" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed; background-color: #F8FAFC; padding: 30px 10px;">
        <tr>
            <td align="center">
                <table class="email-card" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #FFFFFF; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); border: 1px solid #E2E8F0;">
                    
                    {{-- Header --}}
                    <tr>
                        <td class="card-header-bg" style="padding: 24px 32px; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #FFFFFF;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td>
                                        <div style="font-size: 22px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 4px;">
                                            🚢 SeaPass Ferry Transit
                                        </div>
                                        <div style="font-size: 14px; opacity: 0.9;">
                                            Important Schedule Update Notice
                                        </div>
                                    </td>
                                    <td align="right" valign="top">
                                        <span style="background-color: rgba(255, 255, 255, 0.2); color: #FFFFFF; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase;">
                                            Rescheduled
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding: 28px 32px;">
                            <p style="margin: 0 0 16px 0; font-size: 15px; color: #334155; line-height: 1.6;">
                                Dear <strong>{{ $booking->passenger_name }}</strong>,
                            </p>
                            <p style="margin: 0 0 20px 0; font-size: 14px; color: #475569; line-height: 1.6;">
                                Please be informed that your upcoming ferry trip has been rescheduled due to <strong>{{ $reason }}</strong>. Your ticket and reserved seats have been automatically transferred to the nearest available sailing.
                            </p>

                            {{-- Notice Callout --}}
                            <div class="alert-banner" style="background-color: #FFF7ED; border-left: 4px solid #F97316; padding: 14px 18px; border-radius: 6px; margin-bottom: 24px;">
                                <div style="font-size: 13px; font-weight: 700; color: #9A3412; margin-bottom: 2px;">
                                    Booking Reference: {{ $booking->reference_number ?? ('SP-' . $booking->id) }}
                                </div>
                                <div style="font-size: 13px; color: #C2410C;">
                                    Your existing QR code and boarding pass remain fully valid for this new schedule.
                                </div>
                            </div>

                            {{-- Schedule Comparison Table --}}
                            <table class="info-card-bg" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 16px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <div class="info-label" style="font-size: 11px; text-transform: uppercase; color: #64748B; font-weight: 700; margin-bottom: 4px;">Route</div>
                                        <div class="route-highlight" style="font-size: 16px; font-weight: 700; color: #0284C7;">
                                            {{ $newTrip->route }}
                                        </div>
                                    </td>
                                </tr>

                                @if(!empty($oldTripData))
                                <tr>
                                    <td style="padding: 14px 20px; border-bottom: 1px dashed #CBD5E1; background-color: rgba(239, 68, 68, 0.05);">
                                        <div class="info-label" style="font-size: 11px; text-transform: uppercase; color: #991B1B; font-weight: 700; margin-bottom: 2px;">Original Schedule</div>
                                        <div style="font-size: 13px; color: #64748B; text-decoration: line-through;">
                                            {{ $oldTripData['date'] ?? '' }} at {{ $oldTripData['departure_time'] ?? '' }}
                                            @if(!empty($oldTripData['boat_name']))
                                                ({{ $oldTripData['boat_name'] }})
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                <tr>
                                    <td style="padding: 16px 20px; border-bottom: 1px solid #E2E8F0; background-color: rgba(16, 185, 129, 0.05);">
                                        <div class="info-label" style="font-size: 11px; text-transform: uppercase; color: #065F46; font-weight: 700; margin-bottom: 2px;">New Departure Schedule</div>
                                        <div class="info-value" style="font-size: 18px; font-weight: 800; color: #047857;">
                                            📅 {{ $newTrip->departure_time->format('l, F j, Y') }} at {{ $newTrip->departure_time->format('g:i A') }}
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding: 14px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td width="50%">
                                                    <div class="info-label" style="font-size: 11px; text-transform: uppercase; color: #64748B; font-weight: 700; margin-bottom: 2px;">Assigned Vessel</div>
                                                    <div class="info-value" style="font-size: 14px; font-weight: 700; color: #1E293B;">
                                                        🚢 {{ $newTrip->boat->name ?? 'Ferry Vessel' }}
                                                    </div>
                                                </td>
                                                <td width="50%">
                                                    <div class="info-label" style="font-size: 11px; text-transform: uppercase; color: #64748B; font-weight: 700; margin-bottom: 2px;">Reserved Seats</div>
                                                    <div class="info-value" style="font-size: 14px; font-weight: 700; color: #1E293B;">
                                                        🪑 {{ is_array($booking->seat_numbers) ? implode(', ', $booking->seat_numbers) : ($booking->seat_numbers ?? 'Standard Seat') }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Instructions --}}
                            <div style="background-color: #F1F5F9; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;">
                                <div style="font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Important Reminders:</div>
                                <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #64748B; line-height: 1.5;">
                                    <li>Please arrive at the terminal at least <strong>30 minutes</strong> prior to new departure time.</li>
                                    <li>Have your original QR code ticket ready on your mobile device or printed for boarding scan.</li>
                                    <li>If this updated schedule does not work for you, you may contact port support or manage your booking via the SeaPass mobile app.</li>
                                </ul>
                            </div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="footer-bg" style="padding: 20px 32px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0; text-align: center;">
                            <div class="footer-text" style="font-size: 12px; color: #94A3B8;">
                                &copy; {{ date('Y') }} SeaPass Port & Ferry Passenger Management System. All rights reserved.
                            </div>
                            <div class="footer-text" style="font-size: 11px; color: #CBD5E1; margin-top: 4px;">
                                This is an automated notification. Please do not reply directly to this email.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
