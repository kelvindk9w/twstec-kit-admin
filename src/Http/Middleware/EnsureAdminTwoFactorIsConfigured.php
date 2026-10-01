<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Http\Middleware\EnsureTwoFactorIsConfigured;
use Twstec\Kit\Auth\Support\TwoFactorRequirement;

/**
 * /admin com o segundo fator OBRIGATÓRIO (AUTH_TWO_FACTOR_REQUIRED=admins ou
 * all — regra do twstec/kit-auth): administrador sem o segundo fator não
 * opera o painel até configurá-lo.
 *
 * Roda em DOIS lugares, de propósito:
 *   - como o middleware de "MFA obrigatório" do próprio Filament
 *     (`multiFactorAuthenticationRequiredMiddlewareName`) — nas rotas das
 *     páginas, quando o painel registra o MFA como obrigatório;
 *   - na pilha de autenticação PERSISTENTE do painel (AdminPlugin::authMiddleware),
 *     que vale também nas ações Livewire e é conferida a cada requisição — não
 *     depende de as rotas terem sido registradas (ou cacheadas) com a regra
 *     já ligada.
 *
 * Leva à tela de configuração do segundo fator do FRONT (`two-factor.setup`,
 * a mesma do painel do cliente — uma preferência só para os dois logins),
 * guardando o endereço do /admin como destino. A configuração é a do kit
 * (senha de transação + código por e-mail), não o cadastro de MFA do
 * Filament. Sem a rota no front, 403 — nunca deixa passar.
 *
 * Respeita a carência e as contas protegidas da regra
 * (TwoFactorRequirement::mustSetUpNow).
 */
final class EnsureAdminTwoFactorIsConfigured
{
    public function __construct(
        private readonly TwoFactorRequirement $requirement,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof AuthUser || ! $this->requirement->mustSetUpNow($user)) {
            return $next($request);
        }

        $message = __('auth.two_factor.setup_required');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        abort_unless(Route::has(EnsureTwoFactorIsConfigured::SETUP_ROUTE), Response::HTTP_FORBIDDEN, $message);

        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route(EnsureTwoFactorIsConfigured::SETUP_ROUTE);
    }
}
