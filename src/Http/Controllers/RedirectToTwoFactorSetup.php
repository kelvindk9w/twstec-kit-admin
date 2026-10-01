<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Auth\Http\Middleware\EnsureTwoFactorIsConfigured;

/**
 * A rota "configurar o MFA obrigatório" do Filament
 * (`…/multi-factor-authentication/set-up`), no /admin do kit.
 *
 * A página de fábrica do Filament monta o cadastro com os componentes de
 * gestão de cada provedor — e o provedor do kit não tem nenhum: ligar o
 * segundo fator é ação sensível (senha de transação + código por e-mail),
 * feita na tela de configuração do front, a mesma do painel do cliente. Esta
 * rota só leva para lá, guardando o /admin como destino.
 */
final class RedirectToTwoFactorSetup
{
    public function __invoke(Request $request): Response
    {
        abort_unless(Route::has(EnsureTwoFactorIsConfigured::SETUP_ROUTE), Response::HTTP_FORBIDDEN, __('auth.two_factor.setup_required'));

        if (! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', Filament::getUrl());
        }

        return redirect()->route(EnsureTwoFactorIsConfigured::SETUP_ROUTE);
    }
}
