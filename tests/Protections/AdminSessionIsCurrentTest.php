<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Tests\Fixtures\User;

// =============================================================================
// O /admin encerra a sessão aberta com uma senha ou um estado do segundo fator
// que já mudou (EnsureSessionIsCurrent do twstec/kit-auth, também na pilha
// persistente do painel — que não passa pelo grupo `web`). Antes, só a troca
// de SENHA derrubava as outras sessões do painel (AuthenticateSession do
// framework); ligar ou desligar o segundo fator em outro lugar, não.
// =============================================================================

/**
 * Liga o segundo fator da conta "em outro lugar": a gravação acontece com
 * OUTRA conta autenticada nesta execução — a sessão do teste não é a de quem
 * mudou e, por isso, não é atualizada (EndsOtherSessions).
 */
function toggleTwoFactorElsewhere(User $admin): User
{
    Auth::guard('web')->setUser(User::fixture(['email' => 'outro-aparelho@example.com']));

    $admin->forceFill(['two_factor_enabled_at' => $admin->two_factor_enabled_at === null ? now() : null])->save();

    $fresh = $admin->fresh();
    Auth::guard('web')->setUser($fresh);

    return $fresh;
}

it('segundo fator ligado em outro lugar: a próxima PÁGINA do /admin encerra a sessão e leva ao login do painel', function (): void {
    $admin = $this->admin();

    $this->actingAs($admin)->get('/admin/users')->assertOk();

    toggleTwoFactorElsewhere($admin);

    $this->get('/admin/users')->assertRedirect(filament()->getPanel('admin')->getLoginUrl());

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('segundo fator DESligado em outro lugar: a próxima AÇÃO Livewire do /admin encerra a sessão e leva ao login do painel', function (): void {
    $admin = $this->admin(['two_factor_enabled_at' => now()->subDay()]);

    $html = $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, ListUsers::class);

    toggleTwoFactorElsewhere($admin);

    $this->livewireCall($snapshot, '$refresh')->assertRedirect(filament()->getPanel('admin')->getLoginUrl());

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('a sessão de QUEM mudou o próprio segundo fator continua aberta no /admin', function (): void {
    $admin = $this->admin();

    $this->actingAs($admin)->get('/admin/users')->assertOk();

    // A própria sessão grava a mudança (perfil do /admin): ela acompanha.
    $admin->forceFill(['two_factor_enabled_at' => now()])->save();

    $this->get('/admin/users')->assertOk();

    expect(Auth::guard('web')->id())->toBe($admin->getKey());
});
