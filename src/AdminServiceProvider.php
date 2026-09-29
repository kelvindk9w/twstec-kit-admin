<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin;

use Filament\PanelRegistry;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Twstec\Kit\Admin\Console\MakeAdminUser;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\AdminPanelHardening;
use Twstec\Kit\Admin\Support\FreshAvatarUploads;
use Twstec\Kit\Foundation\Localization\PackageTranslations;

/**
 * Provider do twstec/kit-admin (descoberta automática do Laravel).
 *
 * O painel em si entra pelo AdminPlugin, registrado pelo aplicativo no
 * PanelProvider dele. Este provider liga o que não pode depender de o
 * aplicativo lembrar:
 *
 * - a trilha de auditoria das ações do painel (AdminAudit — toda chamada
 *   Livewire de tela do painel roda com o escopo de auditoria aberto);
 * - as garantias de segurança de todo painel que registra o plugin, conferidas
 *   de novo depois que o aplicativo terminou de configurá-lo
 *   (AdminPanelHardening);
 * - a barreira de origem também na rota de download de exports/imports do
 *   Filament (`/filament/exports/…`), que o Filament registra fora do painel,
 *   só com o grupo `web`: o arquivo gerado A PARTIR do /admin seria entregue
 *   por uma rota que a allowlist não cobria;
 * - o comando `user:make-admin`, as traduções (o aplicativo vence), as views
 *   (`kit-admin::`) e a configuração (a do aplicativo vence).
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->path('config/admin.php'), 'admin');
        $this->mergeConfigFrom($this->path('config/dashboards.php'), 'dashboards');

        $this->app->scoped(FreshAvatarUploads::class);

        // Depois que o Filament montou os painéis (os PanelProviders do
        // aplicativo terminaram de configurá-los) e antes de as rotas deles
        // serem registradas.
        $this->app->afterResolving(PanelRegistry::class, function (PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if ($panel->hasPlugin(AdminPlugin::ID)) {
                    AdminPanelHardening::apply($panel);
                }
            }
        });

        PackageTranslations::register($this->app, $this->path('lang'));
    }

    public function boot(): void
    {
        $this->loadViewsFrom($this->path('resources/views'), 'kit-admin');

        AdminAudit::register();

        if (AdminPanelHardening::enabled()) {
            // Depois de todos os providers: o grupo é criado (com `web`) pelo
            // provider de Actions do Filament, e um `middlewareGroup` dele
            // depois deste push o apagaria.
            $this->app->booted(function (): void {
                $this->app->make(Router::class)->pushMiddlewareToGroup('filament.actions', AdminPlugin::originBarrier());
            });
        } else {
            Log::warning('ADMIN_PROTECTIONS=false: as proteções do painel do twstec/kit-admin estão DESLIGADAS — a barreira de origem (allowlist de IP) do painel e do download de exports, a conferência de acesso (is_admin + conta ativa) do pacote e a transação obrigatória das ações ficam por conta do aplicativo. Ver Twstec\Kit\Admin\Support\AdminPanelHardening.');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([MakeAdminUser::class]);

            $this->publishes([
                $this->path('config/admin.php') => config_path('admin.php'),
                $this->path('config/dashboards.php') => config_path('dashboards.php'),
            ], 'admin-config');
        }
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__).'/'.$relative;
    }
}
