<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · SeaPass</title>
    
    @include('partials.head-icons')
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html { height: 100%; }
        body {
            font-family: var(--font-sans);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            height: 100%;
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            grid-template-rows: 100%;
            background: var(--canvas);
            color: var(--text);
        }

        /* Left: the port, as a full-bleed image with a brand wash over it */
        .stage {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: var(--s-9);
            background-image: url('{{ asset("background_images/Port.jpg") }}');
            background-size: cover;
            background-position: center;
            overflow: hidden;
        }
        .stage::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(to top, rgba(10, 48, 55, .92) 0%, rgba(10, 48, 55, .55) 45%, rgba(10, 48, 55, .28) 100%);
        }
        .stage-copy { position: relative; z-index: 1; max-width: 30ch; }
        .stage-mark {
            width: 58px; height: 58px;
            border-radius: var(--r-md);
            background: rgba(255,255,255,.14) url('{{ asset("images/brand/mark-white.svg") }}') center/36px no-repeat;
            backdrop-filter: blur(8px);
            margin-bottom: var(--s-6);
        }
        .stage-title {
            font-size: 38px;
            font-weight: 700;
            letter-spacing: -0.028em;
            line-height: 1.12;
            color: #fff;
            margin-bottom: var(--s-3);
        }
        .stage-sub {
            font-size: 16px;
            line-height: 1.5;
            color: rgba(255,255,255,.72);
        }

        /* Right: the form */
        .panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--s-7);
            background: var(--surface);
        }
        .login-card { width: 100%; max-width: 372px; }

        .login-eyebrow {
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: 0.045em;
            text-transform: uppercase;
            color: var(--text-3);
            margin-bottom: var(--s-3);
        }
        .login-heading {
            font-size: 27px;
            font-weight: 700;
            letter-spacing: -0.022em;
            color: var(--text);
            margin-bottom: var(--s-2);
        }
        .login-note { font-size: 15px; color: var(--text-2); margin-bottom: var(--s-7); }

        .form-group { position: relative; margin-bottom: var(--s-3); }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 550;
            color: var(--text-2);
            margin-bottom: 6px;
        }
        .form-input {
            width: 100%;
            height: 46px;
            padding: 0 var(--s-4) 0 42px;
            background: var(--surface-2);
            border: none;
            border-radius: var(--r-md);
            color: var(--text);
            font-family: inherit;
            font-size: 15px;
            transition: box-shadow 160ms var(--ease), background-color 160ms var(--ease);
        }
        .form-input::placeholder { color: var(--text-3); }
        .form-input:focus {
            outline: none;
            background: var(--surface);
            box-shadow: inset 0 0 0 2px var(--accent);
        }
        .input-icon {
            position: absolute;
            left: 14px;
            bottom: 14px;
            width: 18px;
            height: 18px;
            fill: none;
            stroke: var(--text-3);
            stroke-width: 1.75;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: none;
        }

        .btn-login {
            width: 100%;
            height: 46px;
            margin-top: var(--s-4);
            border: none;
            border-radius: var(--r-md);
            background: var(--accent);
            color: var(--on-accent);
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 160ms var(--ease), transform 120ms var(--ease);
        }
        .btn-login:hover { background: var(--accent-strong); }
        .btn-login:active { transform: scale(.985); }

        .login-error {
            display: flex;
            gap: var(--s-2);
            align-items: flex-start;
            background: var(--danger-tint);
            color: var(--danger-fg);
            padding: 11px var(--s-3);
            border-radius: var(--r-md);
            margin-bottom: var(--s-4);
            font-size: 13.5px;
            line-height: 1.45;
        }

        .login-foot {
            margin-top: var(--s-7);
            font-size: 12.5px;
            color: var(--text-3);
        }

        @media (max-width: 900px) {
            body { grid-template-columns: 1fr; }
            .stage { min-height: 34vh; padding: var(--s-6); justify-content: flex-end; }
            body { grid-template-rows: auto 1fr; height: auto; }
            .stage-title { font-size: 28px; }
            .stage-mark { width: 46px; height: 46px; margin-bottom: var(--s-4); background-size: 28px; }
            .panel { padding: var(--s-6) var(--s-5) var(--s-8); align-items: flex-start; }
        }
    </style>
    <script>
        // Ensure fields are always blank on page load/refresh
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
        });
        
        // Also clear on page visibility change (when user navigates back)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                document.getElementById('username').value = '';
                document.getElementById('password').value = '';
            }
        });
    </script>
</head>
<body>
    <section class="stage">
        <div class="stage-copy">
            <div class="stage-mark" role="img" aria-label="SeaPass"></div>
            <h1 class="stage-title">San Jose Port ticketing, in one place.</h1>
            <p class="stage-sub">Schedules, seats, boarding passes and daily sales for the Surigao ⇄ San Jose lancha route.</p>
        </div>
    </section>

    <main class="panel">
        <div class="login-card">
            <p class="login-eyebrow">SeaPass Admin</p>
            <h2 class="login-heading">Sign in</h2>
            <p class="login-note">Use your port staff account to continue.</p>

            @if ($errors->any())
                <div class="login-error" role="alert">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span>{{ $errors->first('username') }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" autocomplete="off">
                @csrf

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" name="username" id="username"
                           class="form-input @error('username') is-invalid @enderror"
                           placeholder="you@seapass.ph" required autocomplete="off"
                           value="{{ old('username') }}">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M17 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-3A3.5 3.5 0 0 0 7 18.5V20"/>
                        <circle cx="12" cy="8" r="3.4"/>
                    </svg>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" name="password" id="password" class="form-input"
                           placeholder="••••••••" required autocomplete="new-password" value="">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/>
                        <path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/>
                    </svg>
                </div>

                <button type="submit" class="btn-login">Log in</button>
            </form>

            <p class="login-foot">SeaPass Ticketing System · San Jose Port, Dinagat Islands</p>
        </div>
    </main>
</body>
</html>
