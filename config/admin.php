<?php

declare(strict_types=1);

// =============================================================================
// Painel /admin (twstec/kit-admin).
//
// As proteções do painel são ligadas PELO PACOTE, em qualquer painel Filament
// que registre o AdminPlugin — o aplicativo não precisa lembrar de nenhuma:
//
// - a barreira de ORIGEM (allowlist de IP, `security.admin.allowed_ips` do
//   twstec/kit-foundation) como o PRIMEIRO middleware do painel e persistente
//   no endpoint de atualização do Livewire (as ações dos componentes chegam
//   por ali), e também na rota de download de exports/imports do Filament
//   (`/filament/exports/…`), que fica fora do painel;
// - o acesso só de administrador (`is_admin`) com conta ATIVA, conferido pelo
//   próprio pacote a cada requisição do painel e a cada ação Livewire — mesmo
//   que o model de usuário do aplicativo não implemente o `canAccessPanel`
//   do Filament (em ambiente local o Filament deixaria qualquer conta entrar);
// - Actions e Criar/Salvar em transação (a base da trilha de auditoria que
//   falha FECHADA) e a própria trilha de auditoria das ações;
// - a verificação em duas etapas por e-mail no login do painel.
//
// OPT-OUT: só explícito, e o pacote grava um AVISO no log a cada boot.
// ADMIN_PROTECTIONS=false desliga a barreira de origem do painel, a conferência
// de acesso do pacote e a transação obrigatória (o aplicativo assume as três);
// a trilha de auditoria e o segundo fator continuam ligados.
// =============================================================================

return [

    'protections' => filter_var(env('ADMIN_PROTECTIONS', true), FILTER_VALIDATE_BOOL),

];
