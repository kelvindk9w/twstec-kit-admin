<?php

declare(strict_types=1);

use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimits;
use Twstec\Kit\Admin\Resources\Accounts\Pages\ViewAccount;
use Twstec\Kit\Admin\Resources\Accounts\Support\SetApiLimitsAction;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// Limites da API por conta no /admin: o operador com a permissão SOBE ou
// BAIXA o que a plataforma dá a uma conta (vazio = padrão), com o antes/
// depois na trilha. Quem não tem `accounts.set_api_limits` é recusado no
// servidor, com a recusa registrada.
// =============================================================================

beforeEach(function (): void {
    config()->set('api_keys.limits.enabled', true);
    config()->set('api_keys.limits.quota', 25000);
    config()->set('api_keys.limits.concurrency', 50);

    $this->conta = app(AccountService::class)->createAccount('Empresa Limitada', User::fixture(['email_verified_at' => now()]));
});

it('o detalhe mostra o que vale, o da plataforma e a redução do dono', function (): void {
    app(ApiLimits::class)->setOwn($this->conta, ['concurrency' => 7]);

    $this->actingAs($this->admin())->get("/admin/accounts/{$this->conta->uuid}")
        ->assertOk()
        ->assertSee(__('admin.accounts.section_api_limits'))
        ->assertSee(__('admin.accounts.api_limits_summary', [
            'effective' => '25.000',
            'platform' => __('admin.accounts.api_limits_default', ['value' => '25.000']),
            'own' => __('admin.accounts.api_limits_no_reduction'),
        ]))
        ->assertSee(__('admin.accounts.api_limits_summary', [
            'effective' => '7',
            'platform' => __('admin.accounts.api_limits_default', ['value' => '50']),
            'own' => '7',
        ]));
});

it('o operador sobe e baixa os limites da conta (vazio = padrão), com o antes/depois na trilha', function (): void {
    $operador = $this->admin();
    $this->actingAs($operador);

    Livewire::test(ViewAccount::class, ['record' => $this->conta->uuid])
        ->callAction(SetApiLimitsAction::NAME, data: ['quota' => '100000', 'burst_per_minute' => '', 'concurrency' => '5'])
        ->assertHasNoActionErrors();

    expect(app(ApiLimits::class)->effective($this->conta->refresh())->toArray())
        ->toBe(['quota' => 100000, 'burst_per_minute' => (int) config('api_keys.limits.burst_per_minute'), 'concurrency' => 5]);

    $linha = AuditEvent::query()->where('action', SetApiLimitsAction::AUDIT_ACTION)->sole();

    expect($linha->outcome)->toBe(AuditOutcome::Success)
        ->and($linha->actor_uuid)->toBe((string) $operador->uuid)
        ->and($linha->subject_uuid)->toBe((string) $this->conta->uuid)
        ->and($linha->changes)->toMatchArray([
            'api_quota' => ['before' => null, 'after' => 100000],
            'api_concurrency' => ['before' => null, 'after' => 5],
        ]);

    // Valor fora do intervalo não grava.
    Livewire::test(ViewAccount::class, ['record' => $this->conta->uuid])
        ->callAction(SetApiLimitsAction::NAME, data: ['quota' => '0'])
        ->assertHasActionErrors(['quota']);

    expect(app(ApiLimits::class)->platformOverrides($this->conta->refresh())['quota'])->toBe(100000);

    // Vazio volta ao padrão.
    Livewire::test(ViewAccount::class, ['record' => $this->conta->uuid])
        ->callAction(SetApiLimitsAction::NAME, data: ['quota' => '', 'concurrency' => ''])
        ->assertHasNoActionErrors();

    expect(app(ApiLimits::class)->platformOverrides($this->conta->refresh()))
        ->toBe(['quota' => null, 'burst_per_minute' => null, 'concurrency' => null]);
});

it('sem `accounts.set_api_limits` (auditor) a ação é recusada no servidor, com a recusa na trilha', function (): void {
    $html = $this->actingAs($this->admin())->get("/admin/accounts/{$this->conta->uuid}")->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, ViewAccount::class);

    auth()->user()->forceFill(['admin_role' => 'auditor'])->save();

    $this->livewireCall($snapshot, 'mountAction', [], [SetApiLimitsAction::NAME])->assertForbidden();

    expect(AuditEvent::query()->where('outcome', AuditOutcome::Denied)->sole()->reason)->toContain('accounts.set_api_limits')
        ->and(app(ApiLimits::class)->platformOverrides($this->conta->refresh())['quota'])->toBeNull();
});
