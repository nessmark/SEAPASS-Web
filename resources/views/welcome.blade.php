<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketingSystem - Login</title>
    
    {{-- Favicon / Tab Icon --}}
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/seapass_logo.png') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/seapass_logo.png') }}">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-image: url('{{ asset("background_images/Port.jpg") }}');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            padding: 20px;
            position: relative;
        }

        /* Overlay for better readability */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.3);
            z-index: 0;
        }

        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 450px;
        }

        .title {
            text-align: center;
            color: #ffffff;
            font-size: 32px;
            font-weight: 400;
            margin-bottom: 30px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }

        .title .system {
            font-weight: 600;
            color: #ffffff;
        }

        .login-card {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            width: 100%;
        }

        .login-heading {
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 25px;
            font-weight: 400;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background-color: #ffffff;
        }

        .form-input:focus {
            outline: none;
            border-color: #80bdff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .form-input::placeholder {
            color: #6c757d;
        }

        .input-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            pointer-events: none;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background-color: #5a6c7d;
            color: #ffffff;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-top: 10px;
        }

        .btn-login:hover {
            background-color: #4a5a6a;
        }

        .btn-login:active {
            background-color: #3a4a5a;
        }

        /* SVG Icons */
        .icon-user {
            fill: #6c757d;
        }

        .icon-lock {
            fill: #6c757d;
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
    <div class="container">
        <h1 class="title">
            <div>
                <span class="system">San Jose Port</span>
            </div>
                <span class="system">SeaPass Ticketing System</span>
        </h1>
        
        <div class="login-card">
            <p class="login-heading">Sign in to start your session</p>
            
            @if ($errors->any())
                <div style="background-color: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 14px;">
                    {{ $errors->first('username') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" autocomplete="off">
                @csrf
                
                <div class="form-group">
                    <input 
                        type="text" 
                        name="username" 
                        id="username" 
                        class="form-input @error('username') is-invalid @enderror" 
                        placeholder="Username" 
                        required 
                        autocomplete="off"
                        value="{{ old('username') }}"
                    >
                    <svg class="input-icon icon-user" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </div>
                
                <div class="form-group">
                    <input 
                        type="password" 
                        name="password" 
                        id="password" 
                        class="form-input" 
                        placeholder="Password" 
                        required 
                        autocomplete="new-password"
                        value=""
                    >
                    <svg class="input-icon icon-lock" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                    </svg>
                </div>
                
                <button type="submit" class="btn-login">Log In</button>
            </form>
        </div>
    </div>
</body>
</html>
