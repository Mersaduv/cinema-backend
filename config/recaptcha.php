<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA
    |--------------------------------------------------------------------------
    |
    | If the secret key is not configured (empty), we skip verification.
    | This keeps local/dev working while still enforcing CAPTCHA in production.
    |
    */

    'site_key' => '6LePPXgtAAAAAAkzrRuRKsDgxLDZyjcPH0N4VraA',
    'secret' => '6LePPXgtAAAAANULT1yp3YDRkck4yrebSgRg7SM3',
    // Your server currently cannot validate Google's certificate chain.
    // Set to true if you fix CA bundle; keep false temporarily to unblock CAPTCHA verification.
    'verify_ssl' => env('RECAPTCHA_VERIFY_SSL', false),
];

