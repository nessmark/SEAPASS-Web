<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>SeaPass Booking Confirmation - Ticket #{{ $booking->reference_number ?? 'Confirmed' }}</title>
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
            .ref-badge {
                background-color: #0F766E !important;
                color: #CCFBF1 !important;
            }
            .qr-container {
                background-color: #1E293B !important;
                border-color: #334155 !important;
            }
            .notice-box {
                background-color: #064E3B !important;
                border-left-color: #10B981 !important;
                color: #ECFDF5 !important;
            }
            .notice-box p {
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
    @php
        $refNumber = $booking->reference_number ?? ('SP-' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT));
        $passengerName = !empty($booking->passenger_name) ? $booking->passenger_name : ($booking->passenger?->name ?? 'Valued Passenger');
        $vesselName = $booking->tripSchedule?->boat?->name ?? 'Commercial Vessel';
        $tripRoute = $booking->route ?? 'Surigao City ⇄ San Jose (Dinagat)';
        
        // Format departure date
        if ($booking->trip_date instanceof \DateTimeInterface) {
            $formattedDate = $booking->trip_date->format('F d, Y');
        } elseif (!empty($booking->trip_date)) {
            $formattedDate = date('F d, Y', strtotime((string) $booking->trip_date));
        } else {
            $formattedDate = 'Scheduled Departure';
        }

        $timeSlot = $booking->departure_time_slot ?? 'Regular Slot';

        // Format seat numbers
        if (is_array($booking->seat_numbers) && count($booking->seat_numbers) > 0) {
            $seatsDisplay = implode(', ', $booking->seat_numbers);
        } elseif (!empty($booking->seat_numbers)) {
            $seatsDisplay = (string) $booking->seat_numbers;
        } else {
            $seatsDisplay = 'General Allocation';
        }

        // Format total amount
        $amountDisplay = '₱ ' . number_format((float) ($booking->amount_collected ?? 0), 2);

        // QR Code Payload / Reference URL
        $qrCodeData = urlencode($booking->reference_number ?: (string) $booking->id);
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={$qrCodeData}&margin=4";
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
                                    <p style="margin: 2px 0 0 0; color: #0D9488; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Port Ticketing & Passenger System</p>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <!-- Hero Banner -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #0F766E 0%, #0D9488 50%, #0284C7 100%); padding: 32px 28px; text-align: center; color: #FFFFFF;">
                            <div style="display: inline-block; width: 56px; height: 56px; line-height: 56px; border-radius: 50%; background-color: rgba(255, 255, 255, 0.2); font-size: 28px; margin-bottom: 12px;">
                                ✓
                            </div>
                            <h2 style="margin: 0 0 6px 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #FFFFFF;">
                                Booking Confirmed!
                            </h2>
                            <p style="margin: 0; font-size: 14px; opacity: 0.92; color: #E0F2FE; font-weight: 500;">
                                Your sea travel booking has been verified and approved.
                            </p>
                        </td>
                    </tr>

                    <!-- Main Body & E-Ticket Details -->
                    <tr>
                        <td style="padding: 28px 26px 20px 26px;">
                            <p class="subtitle-text" style="margin: 0 0 20px 0; font-size: 15px; color: #475569; line-height: 1.5;">
                                Hello <strong>{{ $passengerName }}</strong>,<br>
                                Thank you for booking with SeaPass. Below is your official electronic ticket summary and boarding pass reference.
                            </p>

                            <!-- Structured E-Ticket Card -->
                            <table class="ticket-card" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; margin-bottom: 24px;">
                                
                                <!-- Card Header / Reference -->
                                <tr>
                                    <td class="ticket-row-border" style="padding: 16px 20px; background-color: #F1F5F9; border-bottom: 1px solid #E2E8F0;" colspan="2">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td align="left">
                                                    <span style="font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.8px;">BOOKING REFERENCE</span><br>
                                                    <span class="ref-badge" style="font-family: 'Courier New', Courier, monospace; font-size: 18px; font-weight: 800; color: #0D9488; letter-spacing: 1px;">{{ $refNumber }}</span>
                                                </td>
                                                <td align="right" valign="middle">
                                                    <span style="background-color: #10B981; color: #FFFFFF; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 5px 12px; border-radius: 9999px; display: inline-block;">
                                                        CONFIRMED
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Passenger Name -->
                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" width="40%">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Passenger Name</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" width="60%" align="right">
                                        <span class="info-value" style="font-size: 14px; font-weight: 700; color: #0F172A;">{{ $passengerName }}</span>
                                    </td>
                                </tr>

                                <!-- Route & Vessel -->
                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Trip Route</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" align="right">
                                        <span class="info-value" style="font-size: 13px; font-weight: 700; color: #0F172A;">{{ $tripRoute }}</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Vessel Name</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" align="right">
                                        <span class="info-value" style="font-size: 14px; font-weight: 700; color: #0F766E;">🚢 {{ $vesselName }}</span>
                                    </td>
                                </tr>

                                <!-- Departure Date & Time -->
                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Departure Date</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" align="right">
                                        <span class="info-value" style="font-size: 14px; font-weight: 700; color: #0F172A;">{{ $formattedDate }}</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Departure Time</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" align="right">
                                        <span class="info-value" style="font-size: 14px; font-weight: 700; color: #0F172A;">{{ $timeSlot }}</span>
                                    </td>
                                </tr>

                                <!-- Assigned Seats -->
                                <tr>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;">
                                        <span class="info-label" style="font-size: 12px; font-weight: 600; color: #64748B;">Assigned Seat(s)</span>
                                    </td>
                                    <td class="ticket-row-border" style="padding: 12px 20px; border-bottom: 1px solid #E2E8F0;" align="right">
                                        <span class="info-value" style="font-size: 14px; font-weight: 800; color: #0D9488; background-color: #CCFBF1; padding: 2px 10px; border-radius: 6px; display: inline-block;">
                                            {{ $seatsDisplay }}
                                        </span>
                                    </td>
                                </tr>

                                <!-- Total Fare -->
                                <tr>
                                    <td style="padding: 14px 20px; background-color: #F8FAFC;">
                                        <span class="info-label" style="font-size: 13px; font-weight: 700; color: #334155;">Total Fare Paid</span>
                                    </td>
                                    <td style="padding: 14px 20px; background-color: #F8FAFC;" align="right">
                                        <span class="info-value" style="font-size: 18px; font-weight: 800; color: #0F172A;">{{ $amountDisplay }}</span>
                                    </td>
                                </tr>
                            </table>

                            <!-- QR Code & Digital Boarding Pass -->
                            <div class="qr-container" style="text-align: center; background-color: #FFFFFF; border: 2px dashed #CBD5E1; border-radius: 14px; padding: 22px 16px; margin-bottom: 24px;">
                                <span style="font-size: 11px; font-weight: 800; color: #64748B; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 12px;">
                                    OFFICIAL SCANNABLE BOARDING QR CODE
                                </span>
                                <div style="display: inline-block; background-color: #FFFFFF; padding: 10px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #E2E8F0;">
                                    <img src="{{ $qrUrl }}" alt="Ticket QR Code" width="160" height="160" style="display: block; width: 160px; height: 160px; margin: 0 auto; border: 0;">
                                </div>
                                <p style="margin: 12px 0 0 0; font-size: 12px; color: #64748B; font-weight: 500;">
                                    Present this scannable QR code directly at the port terminal gate or turnstile.
                                </p>
                            </div>

                            <!-- Boarding Guidelines & Passenger Advisory -->
                            <div class="notice-box" style="background-color: #F0FDF4; border-left: 4px solid #10B981; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px;">
                                <h4 style="margin: 0 0 6px 0; color: #065F46; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                                    Boarding Reminders
                                </h4>
                                <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: #047857; line-height: 1.6;">
                                    <li>Please arrive at the terminal at least <strong>30 minutes</strong> before departure.</li>
                                    <li>Present this email or the digital ticket in the <strong>SeaPass Mobile App</strong> along with a valid Government or Student/Senior ID.</li>
                                    <li>Boarding closes 10 minutes prior to scheduled vessel departure.</li>
                                </ul>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer-bg" align="center" style="padding: 24px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0;">
                            <p class="footer-text" style="margin: 0 0 6px 0; font-size: 12px; color: #64748B;">
                                Need assistance with your reservation? Contact Port Support at <a href="mailto:support@seapass.ph" style="color: #0D9488; text-decoration: none; font-weight: 600;">support@seapass.ph</a>
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
