<?php

declare(strict_types=1);

// Strings of the `panel` group (the application's user panel) reused by the
// /admin screens — same label on both panels. An application that defines
// them in its own lang/ wins; otherwise these fill in.

return [
    'common' => [
        'created_at' => 'Created at',
        'name' => 'Name',
        'save' => 'Save',
        'status' => 'Status',
    ],
    'profile' => [
        'avatar_heading' => 'Profile photo',
        'two_factor_confirm_disable' => 'To turn off two-step verification, confirm with your transaction password and the code sent by email.',
        'two_factor_confirm_enable' => 'To turn on two-step verification, confirm with your transaction password and the code sent by email.',
        'two_factor_disable' => 'Turn off two-step verification',
        'two_factor_enable' => 'Turn on two-step verification',
        'two_factor_heading' => 'Two-step verification',
        'two_factor_hint' => 'When signing in, besides your password, we ask for a code sent to :email. Someone who finds out your password still can\'t get in.',
        'two_factor_off' => 'Off',
        'two_factor_on' => 'On',
        'two_factor_recovery' => 'The code always goes to the account email: without access to it, there is no way to finish signing in. Keep that email secure.',
    ],
    'sensitive' => [
        'code' => 'Verification code',
        'code_hint' => 'We sent a 6-digit code to your email. It expires in a few minutes.',
        'confirm' => 'Confirm and execute',
        'heading' => 'Security confirmation',
        'password_hint' => 'Enter your transaction password to receive a verification code by email.',
        'send_code' => 'Send code by email',
    ],
];
