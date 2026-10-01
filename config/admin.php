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
//
// Papéis/permissões e aprovação em dois passos: ver as seções abaixo.
// =============================================================================

return [

    'protections' => filter_var(env('ADMIN_PROTECTIONS', true), FILTER_VALIDATE_BOOL),

    // =========================================================================
    // PAPÉIS E PERMISSÕES do painel (Authorization\AdminPermissions).
    //
    // Entrar no /admin continua sendo `is_admin` + conta ativa. O QUE cada
    // pessoa pode fazer lá dentro vem do PAPEL dela (coluna `admin_role` da
    // tabela `users`, criada pela migration do pacote). Deny-by-default:
    // admin sem papel, ou com um papel que não existe mais nesta lista, só vê
    // os dashboards e o próprio perfil.
    //
    // Permissão = `<recurso>.<ação>`. O recurso é a chave do resource
    // (BaseResource::permissionKey(): `users`, `api_keys`, `accounts`,
    // `projects`, `uploads`, `request_logs`, `audit`, `approvals`...) ou de
    // uma página (`settings`); a ação é `view`, `create`, `update`,
    // `delete` ou o nome de uma ação própria em snake_case (`block`,
    // `mark_email_verified`, `assign_role`, `revoke`, `approve`...). O
    // padrão aceita `*` (`users.*`, `*.view`, `*`).
    //
    // A checagem é NO SERVIDOR (toda chamada Livewire de tela do painel,
    // além das policies dos resources): esconder o botão é só conforto.
    //
    // Os papéis são CÓDIGO, não dado: mudar o que um papel pode é um diff
    // revisado, não um clique. Quem atribui papel (ação sensível, na tela de
    // usuários) só atribui um papel cujas permissões ele mesmo tem; ninguém
    // muda o próprio papel; o último `super_role` ativo não é rebaixado.
    //
    // OPT-OUT: ADMIN_AUTHORIZATION=false volta ao "tudo ou nada" (todo admin
    // pode tudo), com AVISO no log a cada boot.
    // =========================================================================
    'authorization' => [

        'enabled' => filter_var(env('ADMIN_AUTHORIZATION', true), FILTER_VALIDATE_BOOL),

        // O papel de dono: tem tudo (com ou sem `*` na lista), é o único que
        // atribui ele mesmo e o último ativo não pode ser rebaixado,
        // bloqueado nem excluído. A migration do pacote dá este papel a quem
        // já era `is_admin`, e o `user:make-admin` também.
        'super_role' => 'owner',

        // Rótulo na tela: `admin.roles.<papel>` nas traduções (o aplicativo
        // acrescenta os dele no lang/<idioma>/admin.php); sem tradução, o nome
        // do papel.
        'roles' => [
            'owner' => ['*'],
            'operations' => [
                '*.view',
                'users.create',
                'users.update',
                'users.block',
                'users.unblock',
                'users.delete',
                'users.mark_email_verified',
                'api_keys.revoke',
                'approvals.approve',
                'approvals.reject',
                'approvals.execute',
            ],
            'support' => [
                'users.view',
                'users.mark_email_verified',
                'accounts.view',
                'projects.view',
                'api_keys.view',
                'approvals.view',
            ],
            'auditor' => ['*.view'],
        ],

    ],

    // =========================================================================
    // APROVAÇÃO EM DOIS PASSOS (Approvals\ApprovalService).
    //
    // Uma ação marcada como "exige aprovação" (ApprovableAction registrada E
    // listada em `actions`) não executa: vira um PEDIDO pendente — quem
    // pediu, o quê, o antes/depois redigido, o motivo e a validade. Só
    // executa quando aprovado, uma vez, sob trava, com o estado do registro
    // conferido de novo no momento da execução. Pedido, aprovação, recusa,
    // execução e falha ficam na trilha de auditoria.
    //
    // MODOS:
    // - `four_eyes` (padrão): quem aprova é OUTRA pessoa, com a permissão
    //   `approvals.approve` e a da própria ação. A aprovação executa na hora.
    // - `single_operator`: para equipes de uma pessoa. O mesmo operador pode
    //   aprovar, mas só com a ação sensível (senha de transação + código por
    //   e-mail, sempre — não desligável) e executa num SEGUNDO passo, depois
    //   de uma espera mínima. Modo mais fraco: AVISO no log a cada boot.
    // =========================================================================
    'approvals' => [

        'mode' => env('ADMIN_APPROVALS_MODE', 'four_eyes'),

        // Ações que exigem aprovação (as chaves das ApprovableAction
        // registradas). O kit traz `users.delete` (excluir usuário); o padrão
        // é nenhuma. Ex.: ADMIN_APPROVALS_ACTIONS=users.delete
        'actions' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_APPROVALS_ACTIONS', ''))))),

        // Validade do pedido pendente, em minutos (padrão: 24 h).
        'ttl_minutes' => (int) env('ADMIN_APPROVALS_TTL_MINUTES', 1440),

        // No modo quatro olhos, aprovar também pede a ação sensível (senha de
        // transação + código). Desligar: ADMIN_APPROVALS_SENSITIVE=false, com
        // AVISO no log. No modo de um operador é sempre exigida.
        'sensitive_confirmation' => filter_var(env('ADMIN_APPROVALS_SENSITIVE', true), FILTER_VALIDATE_BOOL),

        'single_operator' => [
            // Espera mínima entre aprovar e executar, em minutos.
            'min_wait_minutes' => (int) env('ADMIN_APPROVALS_MIN_WAIT_MINUTES', 15),
            // Janela para executar depois da espera, em minutos.
            'execution_window_minutes' => (int) env('ADMIN_APPROVALS_EXECUTION_WINDOW_MINUTES', 1440),
        ],

    ],

];
