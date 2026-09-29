<?php

declare(strict_types=1);

// Strings of the `auth` group used by the panel screens that twstec/kit-auth
// does not ship (they belong to the application's front end). An
// application that defines them in its own lang/ wins; otherwise these fill in.

return [
    'two_factor' => [
        'cancel' => 'Back to sign in',
        'resend' => 'Send another code',
        'title' => 'Two-step verification',
    ],
    'ui' => [
        'email' => 'Email',
        'transaction_password_title' => 'Transaction password',
    ],
];
