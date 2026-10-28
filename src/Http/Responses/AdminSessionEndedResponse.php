<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Http\Responses;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Admin\AdminPlugin;
use Twstec\Kit\Auth\Contracts\Responses\SessionEndedResponse;

/**
 * Sessão encerrada porque a senha ou o segundo fator da conta mudaram em
 * outro lugar (EnsureSessionIsCurrent do twstec/kit-auth) — quem estava NO
 * /admin volta ao login DO PAINEL, com o aviso; o resto segue a resposta do
 * aplicativo (a decorada).
 *
 * "No /admin" é o caminho da página ou, numa ação Livewire (que chega pelo
 * endpoint único de atualização, fora do prefixo do painel), o caminho da
 * página em que o componente foi montado — o mesmo critério dos middlewares
 * persistentes do painel. Pedido JSON recebe a resposta decorada (401).
 */
final class AdminSessionEndedResponse implements SessionEndedResponse
{
    public function __construct(
        private readonly SessionEndedResponse $fallback,
    ) {}

    public function toResponse(Request $request): Response
    {
        $panel = $request->expectsJson() ? null : self::panelFor($request);

        if ($panel === null) {
            return $this->fallback->toResponse($request);
        }

        // Aviso, não recusa (a trilha não tem o que registrar: a sessão
        // acabou por uma mudança legítima da conta) — por isso `warning`.
        Notification::make()
            ->warning()
            ->title(__('auth.session_ended'))
            ->send();

        return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl() ?? '/');
    }

    /**
     * O painel do kit em que a requisição estava, ou null.
     */
    public static function panelFor(Request $request): ?Panel
    {
        $path = trim(Livewire::isLivewireRequest() ? (string) Livewire::originalPath() : $request->path(), '/');

        foreach (Filament::getPanels() as $panel) {
            if (! $panel->hasPlugin(AdminPlugin::ID)) {
                continue;
            }

            $prefix = trim($panel->getPath(), '/');

            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $panel;
            }
        }

        return null;
    }
}
