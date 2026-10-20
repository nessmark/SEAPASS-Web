<?php

return [
    'api_key' => env('SEAPASS_GEMINI_API_KEY', env('GEMINI_API_KEY', '')),
    'chat_model' => env('SEAPASS_ASSISTANT_MODEL', 'gemini-3.1-flash-lite'),
    'live_model' => env('SEAPASS_ASSISTANT_LIVE_MODEL', 'gemini-3.8-live'),
    'force_ipv4' => env('SEAPASS_ASSISTANT_FORCE_IPV4', false),
    'max_rounds' => 6,
    'turn_timeout' => 75,
];
