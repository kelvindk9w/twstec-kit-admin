<?php

declare(strict_types=1);

use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Twstec\Kit\Admin\Compat\LegacyNames;
use Twstec\Kit\Admin\Console\MakeAdminUser;
use Twstec\Kit\Admin\Dashboards\DashboardRegistry;
use Twstec\Kit\Admin\Dashboards\GrowthDashboard;
use Twstec\Kit\Admin\Dashboards\OverviewDashboard;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\BaseResource;
use Twstec\Kit\Admin\Support\LegacySessionState;
use Twstec\Kit\Admin\Support\ViewMode;
use Twstec\Kit\Admin\Widgets\Overview\LatestUploads;
use Twstec\Kit\Admin\Widgets\Overview\OverviewStats;

// =============================================================================
// NOMES ANTIGOS (1.x) — o painel morava em App\Filament\… no aplicativo. Por
// uma versão (2.x) os nomes antigos continuam valendo onde podem estar
// GRAVADOS: snapshot de componente Livewire aberto no navegador durante o
// deploy, config publicada que nomeia páginas e widgets, estado de tabela e de
// visualização guardado na sessão pelo nome da classe, e código do projeto que
// ainda não trocou o `use`.
// =============================================================================

it('cada nome antigo da lista resolve para a MESMA classe nova', function (): void {
    foreach (LegacyNames::MAP as $antigo => $novo) {
        expect(class_exists($antigo) || interface_exists($antigo) || trait_exists($antigo) || enum_exists($antigo))
            ->toBeTrue("{$antigo} não resolve")
            ->and((new ReflectionClass($antigo))->getName())->toBe($novo);
    }

    expect(count(LegacyNames::MAP))->toBe(61);
});

it('o nome antigo vale em `instanceof` e em herança (código do projeto que não trocou o use)', function (): void {
    $resource = new UserResource;

    expect($resource)->toBeInstanceOf('App\\Filament\\Support\\BaseResource')
        ->and(is_subclass_of(UserResource::class, 'App\\Filament\\Support\\BaseResource'))->toBeTrue()
        ->and('App\\Filament\\Support\\BaseResource')->toBe('App\\Filament\\Support\\BaseResource')
        ->and(new ReflectionClass('App\\Filament\\Support\\BaseResource'))->getName()->toBe(BaseResource::class);
});

it('o comando user:make-admin responde pelos dois nomes antigos (1.x e starter pré-2.0)', function (): void {
    expect((new ReflectionClass('App\\Core\\Auth\\Console\\MakeAdminUser'))->getName())->toBe(MakeAdminUser::class)
        ->and((new ReflectionClass('App\\Console\\Commands\\MakeAdminUser'))->getName())->toBe(MakeAdminUser::class);
});

it('a lista é FECHADA: peça nova do pacote e tela do aplicativo não ganham nome antigo inventado', function (): void {
    expect(class_exists('App\\Filament\\AdminPlugin'))->toBeFalse()
        ->and(class_exists('App\\Filament\\AdminServiceProvider'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Support\\AdminPanelHardening'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\Qualquer\\QualquerResource'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Http\\Middleware\\EnsureAdminPanelAccess'))->toBeFalse();
});

it('snapshot aberto no navegador durante o deploy: a ação chega com o nome ANTIGO do componente e funciona', function (): void {
    $admin = $this->admin(['name' => 'Nome Antes do Deploy']);

    $html = $this->actingAs($admin)->get('/admin/profile')->assertOk()->getContent();
    $snapshot = json_decode($this->snapshotFrom((string) $html, Profile::class), true);

    // O que o navegador tinha antes do deploy: o nome do componente de uma
    // página do Filament É o nome da classe — o antigo. Assinado como o
    // servidor antigo assinou (mesma APP_KEY).
    $snapshot['memo']['name'] = 'App\\Filament\\Pages\\Profile';
    unset($snapshot['checksum']);
    $snapshot['checksum'] = Checksum::generate($snapshot);

    $this->livewireCall(json_encode($snapshot), 'save', ['data.name' => 'Nome Depois do Deploy'])->assertOk();

    expect($admin->fresh()->name)->toBe('Nome Depois do Deploy');
});

it('o Livewire resolve o nome antigo para a mesma classe da tela nova', function (): void {
    $this->actingAs($this->admin());

    Livewire::test('App\\Filament\\Resources\\Users\\Pages\\ListUsers')->assertOk()->assertSee(__('admin.users.plural'));

    foreach (['App\\Filament\\Pages\\Profile' => Profile::class, 'App\\Filament\\Resources\\Users\\Pages\\ListUsers' => ListUsers::class] as $antigo => $novo) {
        expect((new ReflectionClass(app('livewire.factory')->resolveComponentClass($antigo)))->getName())->toBe($novo);
    }
});

it('config de dashboards publicada com os nomes antigos continua funcionando, já com os nomes novos', function (): void {
    config()->set('dashboards.variants.overview.page', 'App\\Filament\\Dashboards\\OverviewDashboard');
    config()->set('dashboards.variants.growth.page', 'App\\Filament\\Dashboards\\GrowthDashboard');
    config()->set('dashboards.widgets.overview', [
        ['widget' => 'App\\Filament\\Widgets\\Overview\\LatestUploads', 'before' => 'App\\Filament\\Widgets\\Overview\\OverviewStats'],
    ]);

    expect(DashboardRegistry::pages())->toBe([OverviewDashboard::class, GrowthDashboard::class])
        ->and(DashboardRegistry::widgets('overview', [OverviewStats::class]))->toBe([LatestUploads::class, OverviewStats::class]);
});

it('estado de tabela gravado na sessão pelo nome antigo passa para o novo — e só uma vez (idempotente)', function (): void {
    $antigo = 'App\\Filament\\Resources\\Users\\Pages\\ListUsers';
    $novo = ListUsers::class;

    session()->put('tables.'.md5($antigo).'_per_page', 50);
    session()->put('tables.'.md5($antigo).'_sort', 'email:asc');
    // Escolha feita DEPOIS da atualização vence a antiga.
    session()->put('tables.'.md5($novo).'_sort', 'created_at:desc');

    LegacySessionState::migrateTable($novo);

    expect(session('tables.'.md5($novo).'_per_page'))->toBe(50)
        ->and(session('tables.'.md5($novo).'_sort'))->toBe('created_at:desc')
        ->and(session()->has('tables.'.md5($antigo).'_per_page'))->toBeFalse()
        ->and(session()->has('tables.'.md5($antigo).'_sort'))->toBeFalse();

    // Rodar de novo não muda nada.
    $antes = session()->all();
    LegacySessionState::migrateTable($novo);
    expect(session()->all())->toBe($antes);
});

it('a listagem aberta migra sozinha o estado de tabela antigo (itens por página)', function (): void {
    $this->actingAs($this->admin());

    session()->put('tables.'.md5('App\\Filament\\Resources\\Users\\Pages\\ListUsers').'_per_page', 25);

    Livewire::test(ListUsers::class)->assertSet('tableRecordsPerPage', 25);
});

it('o modo tabela/cards escolhido antes da atualização continua valendo', function (): void {
    session()->put(ViewMode::sessionKey('App\\Filament\\Resources\\Users\\UserResource'), 'grid');

    expect(ViewMode::for(UserResource::class))->toBe(ViewMode::Grid)
        ->and(session()->has(ViewMode::sessionKey('App\\Filament\\Resources\\Users\\UserResource')))->toBeFalse()
        ->and(session(ViewMode::sessionKey(UserResource::class)))->toBe('grid');
});
