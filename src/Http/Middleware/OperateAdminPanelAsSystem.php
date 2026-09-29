<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Foundation\Kit;

/**
 * O /admin opera em MODO SISTEMA, declarado: o operador da plataforma vê
 * projetos e chaves de TODAS as contas (com a conta de cada um nas telas).
 *
 * Fica na autenticação do painel, depois do Authenticate e do
 * EnsureAdminPanelAccess — só quem já passou pelas duas entra no modo
 * sistema — e é PERSISTENTE, como elas: vale também no endpoint de
 * atualização do Livewire, por onde chegam as ações e os widgets. As ações do
 * painel já vão para a trilha de auditoria (AdminAudit).
 *
 * Não depende de ADMIN_PROTECTIONS: sem o modo sistema, o painel não teria
 * conta atual e as telas de projetos e chaves falhariam (o escopo das contas
 * não devolve tudo por falta de conta).
 *
 * Sem o twstec/kit-accounts instalado não há contas nem escopo: o middleware
 * só deixa passar.
 */
final class OperateAdminPanelAsSystem
{
    public function handle(Request $request, Closure $next): Response
    {
        // Até o fim da requisição (e não só em volta do $next): nas ações, o
        // Livewire reaplica os middlewares persistentes num pipeline à parte,
        // ANTES de rodar o componente — um modo sistema só em volta do $next
        // acabaria antes da ação. O fim da requisição o desfaz sozinho.
        if (Kit::has('accounts')) {
            Accounts::systemModeForRequest('admin');
        }

        return $next($request);
    }
}
