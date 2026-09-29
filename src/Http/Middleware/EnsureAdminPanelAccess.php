<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Admin\Access\AdminAccess;

/**
 * Só administrador com conta ATIVA opera o painel — conferido pelo PACOTE.
 *
 * Vem logo depois do Authenticate do Filament (que manda quem não está logado
 * para o login) e é PERSISTENTE: vale também no endpoint de atualização do
 * Livewire, por onde chegam as ações dos componentes do painel. O Filament só
 * recusa por conta própria quando o model de usuário implementa o contrato
 * dele (FilamentUser::canAccessPanel) — e, em ambiente local, deixa entrar
 * qualquer conta de um model que não o implementa. Este middleware não
 * depende disso: o critério é Access\AdminAccess, em qualquer ambiente.
 *
 * Opt-out explícito: `admin.protections = false` (ADMIN_PROTECTIONS), com aviso
 * no log a cada boot.
 */
final class EnsureAdminPanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('admin.protections', true) === false) {
            return $next($request);
        }

        $user = Filament::auth()->user();

        // Sem sessão, quem responde é o Authenticate do Filament (redireciona
        // para o login); este middleware só recusa quem ESTÁ logado.
        if ($user !== null && ! AdminAccess::allows($user)) {
            abort(403);
        }

        return $next($request);
    }
}
