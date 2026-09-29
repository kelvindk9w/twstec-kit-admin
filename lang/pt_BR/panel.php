<?php

declare(strict_types=1);

// Textos do grupo `panel` (o painel do usuário, do aplicativo) que as telas do
// /admin reaproveitam — mesmo rótulo nos dois painéis. O aplicativo que os
// define no próprio lang/ vence; numa aplicação sem eles, estes preenchem.

return [
    'common' => [
        'created_at' => 'Criado em',
        'name' => 'Nome',
        'save' => 'Salvar',
        'status' => 'Status',
    ],
    'profile' => [
        'avatar_heading' => 'Foto do perfil',
        'two_factor_confirm_disable' => 'Para desligar a verificação em duas etapas, confirme com sua senha de transação e o código enviado por e-mail.',
        'two_factor_confirm_enable' => 'Para ligar a verificação em duas etapas, confirme com sua senha de transação e o código enviado por e-mail.',
        'two_factor_disable' => 'Desligar verificação em duas etapas',
        'two_factor_enable' => 'Ligar verificação em duas etapas',
        'two_factor_heading' => 'Verificação em duas etapas',
        'two_factor_hint' => 'Ao entrar, além da senha, pedimos um código enviado para :email. Quem descobrir sua senha ainda não entra.',
        'two_factor_off' => 'Desligada',
        'two_factor_on' => 'Ligada',
        'two_factor_recovery' => 'O código chega sempre ao e-mail da conta: sem acesso a ele, não há como concluir a entrada. Mantenha o e-mail seguro.',
    ],
    'sensitive' => [
        'code' => 'Código de verificação',
        'code_hint' => 'Enviamos um código de 6 dígitos para o seu e-mail. Ele expira em poucos minutos.',
        'confirm' => 'Confirmar e executar',
        'heading' => 'Confirmação de segurança',
        'password_hint' => 'Informe sua senha de transação para receber um código de verificação por e-mail.',
        'send_code' => 'Enviar código por e-mail',
    ],
];
