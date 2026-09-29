<?php

declare(strict_types=1);

// Textos del grupo `auth` que usan las pantallas del panel y que
// twstec/kit-auth no trae (son del front de la aplicación). La aplicación que
// los define en su propio lang/ gana; si no, estos completan.

return [
    'two_factor' => [
        'cancel' => 'Volver al inicio de sesión',
        'resend' => 'Enviar otro código',
        'title' => 'Verificación en dos pasos',
    ],
    'ui' => [
        'email' => 'Correo electrónico',
        'transaction_password_title' => 'Contraseña de transacción',
    ],
];
