<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin;

use Filament\Contracts\Plugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Twstec\Kit\Admin\Auth\EmailCodeAuthentication;
use Twstec\Kit\Admin\Dashboards\DashboardRegistry;
use Twstec\Kit\Admin\Http\Middleware\EnsureAdminPanelAccess;
use Twstec\Kit\Admin\Http\Middleware\OperateAdminPanelAsSystem;
use Twstec\Kit\Admin\Pages\Auth\Login;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Admin\Pages\Settings;
use Twstec\Kit\Admin\Resources\Accounts\AccountResource;
use Twstec\Kit\Admin\Resources\ApiKeys\ApiKeyResource;
use Twstec\Kit\Admin\Resources\AuditEvents\AuditEventResource;
use Twstec\Kit\Admin\Resources\Projects\ProjectResource;
use Twstec\Kit\Admin\Resources\RequestLogs\RequestLogResource;
use Twstec\Kit\Admin\Resources\Uploads\UploadResource;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\AdminPanelHardening;
use Twstec\Kit\Admin\Support\InitialsAvatarProvider;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;
use Twstec\Kit\Foundation\Security\Middleware\EnsureAdminIpAllowed;
use Twstec\Kit\Foundation\Security\Middleware\UseEvalBundleForAdmin;

/**
 * O super admin do kit como PLUGIN do Filament.
 *
 * O painel é do APLICATIVO (o PanelProvider dele decide id, caminho, marca,
 * cores, fonte, tema e o que mais acrescentar); este plugin traz o produto:
 *
 * - os resources (usuários, chaves de API, projetos, uploads, logs de
 *   requisição, auditoria — os de módulo opcional só com o módulo
 *   instalado, ver RESOURCES), as páginas (perfil, configurações), o login com
 *   verificação em duas etapas por e-mail (provedor MFA próprio, o motor é o
 *   do twstec/kit-auth) e as variantes de dashboard (DashboardRegistry);
 * - a navegação (ordem dos grupos), o menu do usuário, o avatar de iniciais
 *   local e a animação dos números dos dashboards;
 * - as PROTEÇÕES, sempre: a barreira de origem (allowlist de IP) como o
 *   PRIMEIRO middleware e persistente, a pilha de sessão/CSRF do Filament, o
 *   acesso só de admin com conta ativa (persistente) e Actions e Criar/Salvar
 *   em transação — a base da trilha de auditoria que falha FECHADA.
 *
 * O aplicativo registra com `->plugin(AdminPlugin::make())` e personaliza o
 * resto pelo próprio Panel, antes ou depois do plugin. Duas garantias não
 * dependem dessa ordem: a allowlist fica em primeiro lugar e nenhum
 * middleware da pilha entra duas vezes — Support\AdminPanelHardening arruma
 * isso quando o painel é montado (e o pacote confere de novo depois que o
 * aplicativo terminou de configurá-lo).
 *
 * POR QUE PLUGIN, E NÃO UM PanelProvider PRONTO PARA ESTENDER: o painel
 * continua sendo do aplicativo, montado com a API normal do Filament; o kit
 * entra como mais um plugin (a demonstração já entra assim), sem herança nem
 * método a sobrescrever, e o aplicativo pode ter outros painéis ou registrar
 * o kit num painel com outro id/caminho.
 */
final class AdminPlugin implements Plugin
{
    public const ID = 'twstec-kit-admin';

    /**
     * Resources do painel => o módulo OPCIONAL de que dependem (null = só da
     * base do kit). O painel se adapta ao que está instalado (Kit::has): sem
     * `twstec/kit-accounts`, não há telas de contas, chaves nem projetos;
     * sem `twstec/kit-uploads`, não há tela de uploads (nem o campo de foto
     * — ver Support\AvatarUpload). Resource ausente não é registrado: nem
     * menu, nem rota.
     *
     * @var array<class-string, string|null>
     */
    public const RESOURCES = [
        AccountResource::class => 'accounts',
        ApiKeyResource::class => 'accounts',
        AuditEventResource::class => null,
        ProjectResource::class => 'accounts',
        RequestLogResource::class => null,
        UploadResource::class => 'uploads',
        UserResource::class => null,
    ];

    public static function make(): self
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel
            // Login próprio: pré-preenche as credenciais quando uma extensão
            // registra o pré-preenchimento (a demo, só em local) e reinicia o
            // desafio de segundo fator vencido.
            ->login(Login::class)
            // Toda Action e todo Criar/Salvar do painel roda numa transação.
            // É o que faz a trilha de auditoria falhar FECHADA: a linha de
            // `audit_events` é gravada dentro da mesma transação da mudança,
            // e se ela não puder ser gravada a mudança é desfeita (ver
            // Twstec\Kit\Foundation\Audit\AuditTrail). Halt/Cancel (recusa de
            // guarda) confirmam a transação — a tentativa recusada fica
            // registrada.
            ->databaseTransactions()
            // Verificação em duas etapas no login, pelo mecanismo de MFA do
            // Filament com o MOTOR do kit: mesma preferência por conta do
            // painel do cliente, mesmo código por e-mail e mesmos limites (ver
            // EmailCodeAuthentication). Opcional — só pede o código de quem
            // ligou; ligar/desligar fica no perfil (Pages\Profile).
            ->multiFactorAuthentication([EmailCodeAuthentication::make()])
            ->resources(self::resources())
            ->pages([
                Profile::class,
                Settings::class,
                // VARIANTES DE DASHBOARD (config/dashboards.php): a variante
                // padrão responde em /<painel>; as demais em
                // /<painel>/dashboards/{slug}. Desligar um slug em
                // DASHBOARD_ENABLED remove a página do menu E da rota (a
                // classe nem é registrada) — quem decide isso é o
                // DashboardRegistry, nunca esta lista.
                ...DashboardRegistry::pages(),
            ])
            // ORDEM dos grupos do menu. Sem esta lista, o Filament ordena os
            // grupos pela ordem em que os itens são descobertos — e "Sistema"
            // acabava no topo, empurrando os dashboards para o rodapé da
            // barra lateral. Os rótulos são CLOSURES porque o painel é montado
            // antes do SetLocale: avaliar __() aqui congelaria o idioma padrão
            // e o casamento com o grupo declarado por cada resource falharia
            // em espanhol e inglês.
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('admin.nav.group_dashboards')),
                NavigationGroup::make(fn (): string => __('admin.nav.group_management')),
                NavigationGroup::make(fn (): string => __('admin.nav.group_catalog')),
                NavigationGroup::make(fn (): string => __('admin.nav.group_security')),
                NavigationGroup::make(fn (): string => __('admin.nav.group_system')),
            ])
            // Nenhum widget é registrado no PAINEL: cada variante declara os
            // seus em getWidgets(). Widget de painel apareceria em TODAS as
            // variantes de uma vez, que é o oposto de "escolha a sua".
            ->widgets([])
            // Animação de entrada dos números dos dashboards (count-up leve,
            // desligada sozinha em prefers-reduced-motion). Progressive
            // enhancement — sem o script, os números já estão na tela,
            // corretos e formatados.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('kit-admin::dashboard-motion')->render(),
            )
            // Avatar do menu do usuário: foto de perfil de quem já subiu uma
            // (upload validado pelo SecureUploadService) e, sem foto,
            // iniciais desenhadas localmente em SVG. O provider de fábrica
            // chama a ui-avatars.com — CDN externa no caminho de toda página
            // do painel só para desenhar duas letras.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Menu do usuário: perfil, alternador de tema (o Filament o
            // injeta entre os itens de sort negativo e os demais), voltar ao
            // site e sair (acrescentado pelo próprio Filament no fim).
            ->userMenuItems([
                'profile' => MenuItem::make()
                    ->label(fn (): string => __('admin.profile.heading'))
                    ->url(fn (): string => Profile::getUrl())
                    ->icon(Heroicon::OutlinedUserCircle)
                    // Sort negativo = ANTES do alternador de tema.
                    ->sort(-1),
                'site' => MenuItem::make()
                    ->label(fn (): string => __('admin.menu.back_to_site'))
                    ->url(fn (): string => url('/'))
                    ->icon(Heroicon::OutlinedArrowLeftOnRectangle),
            ])
            ->middleware(self::sessionMiddleware())
            ->authMiddleware(self::authMiddleware(), isPersistent: true);

        // Barreira de ORIGEM — primeira da pilha e persistente. Ver
        // AdminPanelHardening::apply().
        AdminPanelHardening::apply($panel);
    }

    public function boot(Panel $panel): void {}

    /**
     * Os resources que valem nesta aplicação: os da base e os dos módulos
     * opcionais instalados.
     *
     * @return list<class-string>
     */
    public static function resources(): array
    {
        return array_keys(array_filter(
            self::RESOURCES,
            static fn (?string $module): bool => $module === null || Kit::has($module),
        ));
    }

    /**
     * A pilha de sessão/cookies/CSRF do painel (a mesma de um painel de
     * fábrica do Filament) mais as duas peças do kit: o bundle JS do
     * Livewire com eval só no painel (o Filament 5 não funciona com o build
     * CSP-safe do Alpine) e o idioma resolvido como no resto do aplicativo
     * (preferência da conta → cookie → padrão da plataforma).
     *
     * @return list<class-string>
     */
    public static function sessionMiddleware(): array
    {
        return [
            UseEvalBundleForAdmin::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
            SetLocale::class,
        ];
    }

    /**
     * Autenticação do painel: o Authenticate do Filament (quem não está logado
     * vai para o login), o acesso só de admin com conta ativa, conferido pelo
     * pacote, e — depois dos dois — o MODO SISTEMA das contas (o painel vê
     * todas as contas; ver OperateAdminPanelAsSystem). Os três persistentes:
     * valem também nas ações Livewire.
     *
     * @return list<class-string>
     */
    public static function authMiddleware(): array
    {
        return [
            Authenticate::class,
            EnsureAdminPanelAccess::class,
            OperateAdminPanelAsSystem::class,
        ];
    }

    /**
     * A barreira de origem do painel.
     *
     * @return class-string
     */
    public static function originBarrier(): string
    {
        return EnsureAdminIpAllowed::class;
    }
}
