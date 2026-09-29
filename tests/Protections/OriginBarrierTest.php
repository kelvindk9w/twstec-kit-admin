<?php

declare(strict_types=1);

use Twstec\Kit\Admin\Pages\Auth\Login;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Foundation\Security\Middleware\EnsureAdminIpAllowed;

// =============================================================================
// BARREIRA DE ORIGEM (allowlist de IP do admin, do twstec/kit-foundation),
// ligada pelo PACOTE numa aplicação limpa: nas páginas do painel, no endpoint
// de atualização do Livewire (por onde chegam as ações dos componentes do
// painel, fora do prefixo /admin) e na rota de download de exports/imports do
// Filament, que também fica fora do painel.
// =============================================================================

beforeEach(function (): void {
    config()->set('security.admin.allowed_ips', ['10.10.10.10']);
    config()->set('security.admin.allow_any_ip', false);
});

it('IP fora da allowlist: 403 no login e nas telas do painel, antes de qualquer sessão', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->get('/admin/login')
        ->assertForbidden()
        ->assertCookieMissing(config('session.cookie'));

    $this->actingAs($this->admin())
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->get('/admin/users')
        ->assertForbidden();
});

it('IP da allowlist: entra', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])->get('/admin/login')->assertOk();

    $this->actingAs($this->admin())
        ->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
        ->get('/admin/users')
        ->assertOk();
});

it('IP fora da allowlist NÃO executa ação de componente do painel pelo endpoint do Livewire', function (): void {
    $admin = $this->admin(['name' => 'Nome Original']);

    $html = $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
        ->get('/admin/profile')
        ->assertOk()
        ->getContent();

    $snapshot = $this->snapshotFrom((string) $html, Profile::class);

    $this->livewireCall($snapshot, 'save', ['data.name' => 'Alterado de Fora'], ip: '203.0.113.99')->assertForbidden();
    expect($admin->fresh()->name)->toBe('Nome Original');

    // De dentro, a mesma ação funciona (a barreira não quebrou o painel).
    $this->livewireCall($snapshot, 'save', ['data.name' => 'Novo Nome'], ip: '10.10.10.10')->assertOk();
    expect($admin->fresh()->name)->toBe('Novo Nome');
});

it('a tela de login do painel chamada pelo endpoint do Livewire também passa pela allowlist', function (): void {
    $html = $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])->get('/admin/login')->assertOk()->getContent();

    $snapshot = $this->snapshotFrom((string) $html, Login::class);

    $this->livewireCall($snapshot, 'authenticate', ip: '203.0.113.99')->assertForbidden();
});

it('a rota de download de exports/imports do Filament, fora do painel, está atrás da barreira', function (): void {
    foreach (['filament.exports.download', 'filament.imports.failed-rows.download'] as $nome) {
        $rota = app('router')->getRoutes()->getByName($nome);

        expect($rota)->not->toBeNull()
            ->and(app('router')->gatherRouteMiddleware($rota))->toContain(EnsureAdminIpAllowed::class);
    }

    expect(app('router')->getMiddlewareGroups()['filament.actions'] ?? [])->toContain('web', EnsureAdminIpAllowed::class);
});

it('em produção, sem allowlist declarada, o painel recusa (regra do twstec/kit-foundation, ligada pelo pacote)', function (): void {
    // Sem banco: a barreira responde antes de qualquer consulta.
    $this->bootWith(['app.env' => 'production', 'security.admin.allowed_ips' => [], 'app.debug' => false], migrate: false);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/admin/login')->assertForbidden();
});
