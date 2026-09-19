<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Payment Successful - SeaPass</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        body {
            background-color: #0b1329;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #131c36;
            width: 100%;
            max-width: 380px;
            border-radius: 20px;
            border: 1px solid #1e293b;
            padding: 32px 24px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        }
        .icon-circle {
            width: 72px;
            height: 72px;
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 2px solid #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 20px;
        }
        h1 { font-size: 22px; font-weight: 800; margin-bottom: 8px; color: #ffffff; }
        p { font-size: 14px; color: #94a3b8; line-height: 1.5; margin-bottom: 24px; }
        .ref-badge {
            background: #0d1527;
            border: 1px solid #233157;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            color: #38bdf8;
            margin-bottom: 24px;
        }
        .btn-done {
            display: block;
            width: 100%;
            background: #10b981;
            color: #ffffff;
            padding: 14px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-circle">✓</div>
        <h1>Payment Received!</h1>
        <p>Your GCash payment has been recorded and verified.</p>
        <div class="ref-badge">Reference #{{ $ref }}</div>
        <a href="javascript:void(0)" onclick="window.close()" class="btn-done">Return to SeaPass App</a>
    </div>
</body>
</html>
