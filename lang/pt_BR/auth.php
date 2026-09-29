<?php

declare(strict_types=1);

// Textos do grupo `auth` que as telas do painel usam e que o twstec/kit-auth
// não traz (são do front do aplicativo). O aplicativo que os define no
// próprio lang/ vence; numa aplicação sem eles, estes preenchem.

return [
    'two_factor' => [
        'cancel' => 'Voltar ao login',
        'resend' => 'Enviar outro código',
        'title' => 'Verificação em duas etapas',
    ],
    'ui' => [
        'email' => 'E-mail',
        'transaction_password_title' => 'Senha de transação',
    ],
];
