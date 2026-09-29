<?php

declare(strict_types=1);

// Textos del grupo `panel` (el panel del usuario, de la aplicación) que
// reutilizan las pantallas de /admin — misma etiqueta en los dos paneles. La
// aplicación que los define en su propio lang/ gana; si no, estos completan.

return [
    'common' => [
        'created_at' => 'Creado el',
        'name' => 'Nombre',
        'save' => 'Guardar',
        'status' => 'Estado',
    ],
    'profile' => [
        'avatar_heading' => 'Foto de perfil',
        'two_factor_confirm_disable' => 'Para desactivar la verificación en dos pasos, confirma con tu contraseña de transacción y el código enviado por correo.',
        'two_factor_confirm_enable' => 'Para activar la verificación en dos pasos, confirma con tu contraseña de transacción y el código enviado por correo.',
        'two_factor_disable' => 'Desactivar verificación en dos pasos',
        'two_factor_enable' => 'Activar verificación en dos pasos',
        'two_factor_heading' => 'Verificación en dos pasos',
        'two_factor_hint' => 'Al iniciar sesión, además de la contraseña, pedimos un código enviado a :email. Quien descubra tu contraseña todavía no entra.',
        'two_factor_off' => 'Desactivada',
        'two_factor_on' => 'Activada',
        'two_factor_recovery' => 'El código siempre llega al correo de la cuenta: sin acceso a él, no hay forma de completar el inicio de sesión. Mantén ese correo seguro.',
    ],
    'sensitive' => [
        'code' => 'Código de verificación',
        'code_hint' => 'Enviamos un código de 6 dígitos a tu correo. Expira en pocos minutos.',
        'confirm' => 'Confirmar y ejecutar',
        'heading' => 'Confirmación de seguridad',
        'password_hint' => 'Ingresa tu contraseña de transacción para recibir un código de verificación por correo.',
        'send_code' => 'Enviar código por correo',
    ],
];
