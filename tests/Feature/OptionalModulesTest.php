<?php

declare(strict_types=1);

use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Admin\AdminPlugin;
use Twstec\Kit\Admin\Dashboards\GrowthDashboard;
use Twstec\Kit\Admin\Dashboards\OverviewDashboard;
use Twstec\Kit\Admin\Resources\Accounts\AccountResource;
use Twstec\Kit\Admin\Resources\ApiKeys\ApiKeyResource;
use Twstec\Kit\Admin\Resources\AuditEvents\AuditEventResource;
use Twstec\Kit\Admin\Resources\Projects\ProjectResource;
use Twstec\Kit\Admin\Resources\RequestLogs\RequestLogResource;
use Twstec\Kit\Admin\Resources\Uploads\UploadResource;
use Twstec\Kit\Admin\Resources\Users\Pages\CreateUser;
use Twstec\Kit\Admin\Resources\Users\Support\UserAdminGuard;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\AvatarUpload;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Admin\Widgets\Growth\GrowthStats;
use Twstec\Kit\Admin\Widgets\Growth\RecentProjectsTable;
use Twstec\Kit\Admin\Widgets\Overview\LatestUploads;
use Twstec\Kit\Admin\Widgets\Overview\OverviewStats;
use Twstec\Kit\Foundation\Kit;

// =============================================================================
// O PAINEL SE ADAPTA AOS MÓDULOS INSTALADOS. twstec/kit-accounts e
// twstec/kit-uploads são opcionais para o admin (composer.json → suggest):
// sem accounts, não há telas de contas, chaves e projetos, nem o filtro por
// conta da trilha, nem o card de chaves/projetos dos dashboards; sem uploads,
// não há tela de uploads, widget dos últimos uploads nem o campo de foto.
//
// Aqui a suíte tem os dois pacotes instalados (require-dev) e FINGE a
// ausência (Kit::pretendAbsent) numa aplicação que sobe de novo — o painel é
// montado do zero, como numa instalação sem o pacote. A prova de que o painel
// SOBE sem os pacotes de verdade é a suíte do starter na combinação
// correspondente (CI).
// =============================================================================

afterEach(function (): void {
    Kit::flushFakes();
});

it('com os módulos instalados, registra todas as telas', function (): void {
    expect(AdminPlugin::resources())->toBe(array_keys(AdminPlugin::RESOURCES))
        ->and($this->panel()->getResources())->toContain(AccountResource::class, ApiKeyResource::class, ProjectResource::class, UploadResource::class);
});

it('sem o pacote de contas, as telas de contas, chaves e projetos não existem — nem menu, nem rota', function (): void {
    Kit::pretendAbsent('accounts', 'uploads');
    $this->bootWith([]);

    expect($this->panel()->getResources())->toEqualCanonicalizing([
        AuditEventResource::class,
        RequestLogResource::class,
        UserResource::class,
    ]);

    $admin = $this->admin();

    foreach (['/admin/accounts', '/admin/api-keys', '/admin/projects', '/admin/uploads'] as $tela) {
        $this->actingAs($admin)->get($tela)->assertNotFound();
    }

    // O resto do painel continua de pé.
    foreach (['/admin', '/admin/users', '/admin/request-logs', '/admin/audit-events', '/admin/settings', '/admin/profile', '/admin/dashboards/growth'] as $tela) {
        $this->actingAs($admin)->get($tela)->assertOk();
    }
});

it('sem o pacote de uploads (com contas), só a tela de uploads some', function (): void {
    Kit::pretendAbsent('uploads');
    $this->bootWith([]);

    expect($this->panel()->getResources())->toContain(AccountResource::class, ApiKeyResource::class, ProjectResource::class)
        ->not->toContain(UploadResource::class);

    $this->actingAs($this->admin())->get('/admin/uploads')->assertNotFound();
    $this->actingAs($this->admin())->get('/admin/accounts')->assertOk();
});

it('os dashboards só levam os widgets dos módulos instalados', function (): void {
    expect((new OverviewDashboard)->getWidgets())->toContain(LatestUploads::class)
        ->and((new GrowthDashboard)->getWidgets())->toContain(RecentProjectsTable::class);

    Kit::pretendAbsent('accounts', 'uploads');

    expect((new OverviewDashboard)->getWidgets())->not->toContain(LatestUploads::class)
        ->toContain(OverviewStats::class)
        ->and((new GrowthDashboard)->getWidgets())->not->toContain(RecentProjectsTable::class)
        ->toContain(GrowthStats::class);
});

it('sem o pacote de contas, os cards de chaves e projetos saem dos números de abertura', function (): void {
    $this->actingAs($this->admin());

    Livewire::test(OverviewStats::class)
        ->assertSee(__('admin.dashboards.overview.api_keys'))
        ->assertSee(__('admin.dashboards.overview.projects'));

    Kit::pretendAbsent('accounts', 'uploads');

    Livewire::test(OverviewStats::class)
        ->assertSee(__('admin.dashboards.overview.users'))
        ->assertDontSee(__('admin.dashboards.overview.api_keys'))
        ->assertDontSee(__('admin.dashboards.overview.projects'));

    Livewire::test(GrowthStats::class)
        ->assertSee(__('admin.dashboards.growth.new_users'))
        ->assertDontSee(__('admin.dashboards.growth.new_api_keys'));
});

it('sem o pacote de uploads, o cadastro de usuário não tem o campo de foto', function (): void {
    $this->actingAs($this->admin());

    Livewire::test(CreateUser::class)->assertFormFieldExists('avatar');

    Kit::pretendAbsent('uploads');

    expect(AvatarUpload::available())->toBeFalse()
        ->and(AvatarUpload::stateFor($this->admin()))->toBeNull()
        ->and(AvatarUpload::denialFor(null, ['0192f7a0-0000-7000-8000-000000000000']))->toBeNull();

    Livewire::test(CreateUser::class)->assertFormFieldDoesNotExist('avatar');
});

it('sem o pacote de contas, a guarda de exclusão não consulta contas', function (): void {
    $dona = User::fixture();
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(AccountService::class)->addMember($empresa, User::fixture(), AccountRole::Member);

    expect(UserAdminGuard::deleteDenial($dona, $this->admin()))->toContain($empresa->codigo_publico);

    Kit::pretendAbsent('accounts', 'uploads');

    // As regras da própria guarda continuam; a das contas não existe.
    expect(UserAdminGuard::deleteDenial($dona, $this->admin()))->toBeNull()
        ->and(UserAdminGuard::deleteDenial($dona, $dona))->toBe(__('admin.users.cannot_delete_self'));
});
