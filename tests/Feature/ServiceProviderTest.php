<?php

declare(strict_types=1);

use Filament\Http\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Artisan;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Twstec\Kit\Admin\AdminPlugin;
use Twstec\Kit\Admin\Auth\EmailCodeAuthentication;
use Twstec\Kit\Admin\Dashboards\GrowthDashboard;
use Twstec\Kit\Admin\Dashboards\OverviewDashboard;
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
use Twstec\Kit\Admin\Tests\Fixtures\AdminPanelProvider;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Admin\Tests\TestCase;
use Twstec\Kit\Foundation\Security\Middleware\EnsureAdminIpAllowed;

// =============================================================================
// FIAÇÃO DO PACOTE numa aplicação limpa: descoberta, configuração (a do
// aplicativo vence), views, comando, e o que o AdminPlugin põe no painel —
// inclusive as garantias que não dependem da ordem em que o aplicativo
// escreveu o PanelProvider.
// =============================================================================

it('é descoberto pelo Laravel com o provider que a suíte registra', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe(TestCase::PACKAGE_PROVIDERS)
        ->and($composer['name'])->toBe('twstec/kit-admin')
        ->and($composer['autoload']['psr-4'])->toBe(['Twstec\\Kit\\Admin\\' => 'src/']);
});

it('traz a própria configuração (admin e dashboards) e a do aplicativo vence', function (): void {
    expect(config('admin.protections'))->toBeTrue()
        ->and(config('dashboards.enabled'))->toBe(['overview', 'growth'])
        ->and(config('dashboards.default'))->toBe('overview')
        ->and(config('dashboards.variants.overview.page'))->toBe(OverviewDashboard::class);

    $this->bootWith(['dashboards.enabled' => ['growth'], 'dashboards.period' => 7]);

    expect(config('dashboards.enabled'))->toBe(['growth'])
        ->and(config('dashboards.period'))->toBe(7)
        // Chave que o aplicativo não mexeu: a do pacote continua.
        ->and(config('dashboards.periods'))->toBe([7, 30, 90])
        ->and(collect($this->panel()->getPages()))->toContain(GrowthDashboard::class)->not->toContain(OverviewDashboard::class);
});

it('registra as views no namespace kit-admin e o comando user:make-admin', function (): void {
    expect(view()->exists('kit-admin::pages.profile'))->toBeTrue()
        ->and(view()->exists('kit-admin::pages.settings'))->toBeTrue()
        ->and(view()->exists('kit-admin::widgets.goal-progress'))->toBeTrue()
        ->and(view()->exists('kit-admin::dashboard-motion'))->toBeTrue()
        ->and(array_keys(Artisan::all()))->toContain('user:make-admin');
});

it('o plugin põe no painel o produto inteiro: resources, páginas, login com segundo fator e dashboards', function (): void {
    $panel = $this->panel();

    expect($panel->hasPlugin(AdminPlugin::ID))->toBeTrue()
        ->and($panel->getResources())->toEqualCanonicalizing([
            AccountResource::class,
            ApiKeyResource::class,
            AuditEventResource::class,
            ProjectResource::class,
            RequestLogResource::class,
            UploadResource::class,
            UserResource::class,
        ])
        ->and($panel->getPages())->toContain(Profile::class, Settings::class, OverviewDashboard::class, GrowthDashboard::class)
        ->and($panel->getLoginRouteAction())->toBe(Login::class)
        ->and($panel->isMultiFactorAuthenticationRequired())->toBeFalse()
        ->and(collect($panel->getMultiFactorAuthenticationProviders())->map(fn ($p) => $p::class)->values()->all())->toBe([EmailCodeAuthentication::class])
        ->and($panel->hasDatabaseTransactions())->toBeTrue();
});

it('a barreira de origem é o PRIMEIRO middleware do painel e é persistente; o acesso de admin também é persistente', function (): void {
    $middleware = $this->panel()->getMiddleware();

    expect($middleware[0])->toBe('panel:admin')
        ->and($middleware[1])->toBe(EnsureAdminIpAllowed::class)
        // + o modo sistema das contas, depois do acesso de admin (F8a).
        ->and($this->panel()->getAuthMiddleware())->toBe([Authenticate::class, EnsureAdminPanelAccess::class, OperateAdminPanelAsSystem::class])
        ->and(app(PersistentMiddleware::class)->getPersistentMiddleware())
        ->toContain(EnsureAdminIpAllowed::class, Authenticate::class, EnsureAdminPanelAccess::class, OperateAdminPanelAsSystem::class)
        ->and(AdminPanelHardening::holds($this->panel()))->toBeTrue();

    // A rota de verdade (o que o Laravel executa), não só a lista do painel.
    $rota = app('router')->getRoutes()->getByName('filament.admin.resources.users.index');
    $pilha = app('router')->gatherRouteMiddleware($rota);

    expect($pilha[0])->toBe('Filament\\Http\\Middleware\\SetUpPanel:admin')
        ->and($pilha[1])->toBe(EnsureAdminIpAllowed::class)
        ->and(array_search(EnsureAdminIpAllowed::class, $pilha, true))->toBeLessThan(array_search(StartSession::class, $pilha, true))
        ->and($pilha)->toContain(EnsureAdminPanelAccess::class);
});

it('não depende da ordem do PanelProvider: pilha declarada ANTES do plugin continua com a barreira em primeiro e sem repetição', function (): void {
    // Um PanelProvider copiado do modelo do Filament: a pilha inteira, e a
    // barreira no fim, antes do plugin.
    AdminPanelProvider::$before = fn ($panel) => $panel->middleware([
        EncryptCookies::class,
        StartSession::class,
        EnsureAdminIpAllowed::class,
    ])->authMiddleware([Authenticate::class]);

    $this->bootWith([]);

    $middleware = $this->panel()->getMiddleware();

    expect($middleware[1])->toBe(EnsureAdminIpAllowed::class)
        ->and(array_count_values($middleware)[StartSession::class])->toBe(1)
        ->and(array_count_values($middleware)[EncryptCookies::class])->toBe(1)
        ->and(array_count_values($middleware)[EnsureAdminIpAllowed::class])->toBe(1)
        ->and(array_count_values($this->panel()->getAuthMiddleware())[Authenticate::class])->toBe(1)
        ->and(AdminPanelHardening::holds($this->panel()))->toBeTrue();
});

it('não depende da ordem do PanelProvider: desligar a transação DEPOIS do plugin não desliga (a trilha falha fechada)', function (): void {
    AdminPanelProvider::$after = fn ($panel) => $panel->databaseTransactions(false)->middleware([StartSession::class]);

    $this->bootWith([]);

    expect($this->panel()->hasDatabaseTransactions())->toBeTrue()
        ->and(array_count_values($this->panel()->getMiddleware())[StartSession::class])->toBe(1)
        ->and(AdminPanelHardening::holds($this->panel()))->toBeTrue();
});

it('sobe o painel numa aplicação limpa: login, listagem de usuários e o dashboard padrão', function (): void {
    $this->get('/admin/login')->assertOk();

    $admin = $this->admin(['email' => 'painel@example.com']);

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('painel@example.com');
    $this->actingAs($admin)->get('/admin/audit-events')->assertOk();
    $this->actingAs($admin)->get('/admin/profile')->assertOk();
    $this->actingAs($admin)->get('/admin/settings')->assertOk();

    expect(User::query()->count())->toBe(1);
});
