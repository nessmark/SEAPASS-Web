<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SeaPass OTP Verification Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f7f6; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed; background-color: #f4f7f6; padding: 40px 10px;">
        <tr>
            <td align="center">
                <!-- Email Container Card -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06); border: 1px solid #e5e9e8;">
                    
                    <!-- Header with Logo -->
                    <tr>
                        <td align="center" style="padding: 32px 30px 20px 30px; background-color: #ffffff; border-bottom: 2px solid #f0f4f3;">
                            @php
                                $logoPath = public_path('images/Email.jpg');
                                $logoCid = (file_exists($logoPath) && isset($message)) ? $message->embed($logoPath) : null;
                            @endphp
                            @if($logoCid)
                                <img src="{{ $logoCid }}" alt="SeaPass Logo" style="max-width: 240px; width: 100%; height: auto; display: block; border: 0; margin: 0 auto;">
                            @else
                                <h1 style="margin: 0; color: #1F2933; font-size: 26px; font-weight: 700; letter-spacing: 0.5px;">SeaPass</h1>
                                <p style="margin: 4px 0 0 0; color: #81C7A8; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">Surigao - San Jose (Dinagat) Port Passenger</p>
                            @endif
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 35px 25px 35px; text-align: center;">
                            <h2 style="margin: 0 0 12px 0; color: #102A43; font-size: 22px; font-weight: 700;">Email Verification Code</h2>
                            <p style="margin: 0 0 26px 0; color: #486581; font-size: 15px; line-height: 1.5;">
                                Thank you for registering with <strong>SeaPass</strong>. Please use the 6-digit One-Time Password (OTP) below to verify your email address.
                            </p>

                            <!-- OTP Box -->
                            <div style="background: linear-gradient(135deg, #F0FDF4 0%, #E6F4EA 100%); border: 2px dashed #81C7A8; border-radius: 12px; padding: 20px 10px; margin: 0 0 26px 0; text-align: center;">
                                <span style="font-family: 'Courier New', Courier, monospace; font-size: 38px; font-weight: 800; color: #0D5C3A; letter-spacing: 8px; display: inline-block; padding-left: 8px;">{{ $otp }}</span>
                            </div>

                            <p style="margin: 0 0 8px 0; color: #627D98; font-size: 14px;">
                                ⏱️ This verification code will expire in <strong>10 minutes</strong>.
                            </p>
                            <p style="margin: 0; color: #829AB1; font-size: 13px;">
                                If you did not request this code, please ignore this email.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 30px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0; text-align: center;">
                            <p style="margin: 0 0 6px 0; color: #475569; font-size: 13px; font-weight: 600;">
                                SeaPass Port Passenger System
                            </p>
                            <p style="margin: 0; color: #94A3B8; font-size: 12px;">
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
