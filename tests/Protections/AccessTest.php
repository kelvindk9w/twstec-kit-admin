<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Twstec\Kit\Admin\AdminServiceProvider;
use Twstec\Kit\Admin\Http\Middleware\EnsureAdminPanelAccess;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Admin\Tests\Fixtures\PlainUser;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Enums\UserStatus;

// =============================================================================
// QUEM ENTRA NO PAINEL, numa aplicação limpa — sem nenhuma linha do starter.
//
// Só administrador (`is_admin`) com conta ATIVA. O pacote confere isso sozinho
// (EnsureAdminPanelAccess, persistente), nas páginas e nas ações Livewire —
// mesmo quando o model de usuário do aplicativo não implementa o contrato do
// Filament, caso em que o Filament, em ambiente local, deixaria qualquer conta
// entrar.
// =============================================================================

it('quem não está logado vai para o login do painel', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/users')->assertRedirect('/admin/login');
});

it('conta que não é admin: sem acesso (403) a nenhuma tela do painel', function (): void {
    $user = User::fixture(['email' => 'comum@example.com']);

    foreach (['/admin', '/admin/users', '/admin/audit-events', '/admin/profile', '/admin/settings'] as $tela) {
        $this->actingAs($user)->get($tela)->assertForbidden();
    }
});

it('admin com a conta bloqueada ou pendente: sem acesso (403)', function (UserStatus $status): void {
    $admin = $this->admin(['status' => $status]);

    $this->actingAs($admin)->get('/admin')->assertForbidden();
    $this->actingAs($admin)->get('/admin/users')->assertForbidden();
})->with([UserStatus::Blocked, UserStatus::Pending]);

it('admin ativo: entra', function (): void {
    $this->actingAs($this->admin())->get('/admin/users')->assertOk();
});

it('model SEM o contrato do Filament, em ambiente LOCAL: quem recusa não-admin e admin inativo é o pacote', function (): void {
    $this->bootWith([
        'app.env' => 'local',
        'auth.providers.users.model' => PlainUser::class,
    ]);

    expect(app()->environment('local'))->toBeTrue();

    $comum = PlainUser::fixture(['email' => 'comum-local@example.com']);
    $inativo = PlainUser::fixture(['email' => 'inativo-local@example.com', 'is_admin' => true, 'status' => UserStatus::Blocked]);
    $admin = PlainUser::fixture(['email' => 'admin-local@example.com', 'is_admin' => true]);

    $this->actingAs($comum)->get('/admin/users')->assertForbidden();

    // Conta inativa: recusada (403) ou com a sessão encerrada e mandada ao
    // login (a proteção de sessão do twstec/kit-auth) — nunca servida.
    $resposta = $this->actingAs($inativo)->get('/admin/users');
    expect($resposta->getStatusCode())->toBeIn([302, 403]);
    if ($resposta->isRedirect()) {
        expect($resposta->headers->get('Location'))->toEndWith('/admin/login');
    }

    $this->actingAs($admin)->get('/admin/users')->assertOk();
});

it('a conferência vale também nas AÇÕES Livewire: conta rebaixada com a página aberta não executa nada', function (): void {
    $admin = $this->admin(['name' => 'Nome Original']);

    $html = $this->actingAs($admin)->get('/admin/profile')->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, Profile::class);

    // Perdeu a flag com a sessão viva.
    $admin->forceFill(['is_admin' => false])->save();

    $this->livewireCall($snapshot, 'save', ['data.name' => 'Alterado'])->assertForbidden();

    expect($admin->fresh()->name)->toBe('Nome Original');
});

it('opt-out explícito (ADMIN_PROTECTIONS=false): o pacote deixa de conferir e AVISA no log a cada boot', function (): void {
    $this->bootWith(['admin.protections' => false]);

    Log::spy();

    (new AdminServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'ADMIN_PROTECTIONS=false'))->atLeast()->once();

    expect(config('admin.protections'))->toBeFalse();

    // Quem decide passa a ser o model (FilamentUser::canAccessPanel), que
    // continua recusando o não-admin; o middleware do pacote só não confere.
    $request = request()->duplicate();
    $passou = false;
    (new EnsureAdminPanelAccess)->handle($request, function () use (&$passou) {
        $passou = true;

        return response('ok');
    });

    expect($passou)->toBeTrue();
});
