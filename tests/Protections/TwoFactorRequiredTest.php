<?php

declare(strict_types=1);

use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Twstec\Kit\Admin\Access\PanelAdministrators;
use Twstec\Kit\Admin\AdminPlugin;
use Twstec\Kit\Admin\Http\Middleware\EnsureAdminPanelAccess;
use Twstec\Kit\Admin\Http\Middleware\EnsureAdminTwoFactorIsConfigured;
use Twstec\Kit\Admin\Http\Middleware\OperateAdminPanelAsSystem;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Contracts\IdentifiesAdministrators;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// /admin COM O SEGUNDO FATOR OBRIGATÓRIO (AUTH_TWO_FACTOR_REQUIRED, issue #22),
// numa aplicação limpa. Quando a regra alcança os administradores (`admins`
// ou `all`), o MFA do Filament fica obrigatório: administrador sem o segundo
// fator não opera nenhuma tela nem ação Livewire do painel até configurá-lo
// (na tela de configuração do front, `two-factor.setup`); desligar pelo
// perfil é recusado no servidor e vai para a trilha.
// =============================================================================

beforeEach(function (): void {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);
});

/**
 * A tela de configuração do segundo fator que o FRONT declara (no starter,
 * a página dele); aqui, uma resposta qualquer com o nome que o kit usa.
 */
function adminTfaDeclareSetupRoute(): void
{
    Route::middleware('web')->get('two-factor/setup', fn (): string => 'tela de configuração do segundo fator')->name('two-factor.setup');
    app('router')->getRoutes()->refreshNameLookups();
}

function adminTfaCode(): string
{
    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::SensitiveAction)
        ->last();

    return $mail->code;
}

it('`none` (padrão): o MFA do painel continua opcional', function (): void {
    expect($this->panel()->isMultiFactorAuthenticationRequired())->toBeFalse();

    $this->actingAs($this->admin())->get('/admin/users')->assertOk();
});

it('`admins` e `all`: o MFA do Filament fica obrigatório, cobrado pelo middleware do kit', function (string $modo): void {
    $this->bootWith(['auth.two_factor.required' => $modo]);

    $panel = $this->panel();

    expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue()
        ->and($panel->getMultiFactorAuthenticationRequiredMiddlewareName())->toBe(EnsureAdminTwoFactorIsConfigured::class)
        ->and($panel->getMultiFactorAuthenticationRequiredMiddlewareName())->not->toBe(EnsureMultiFactorAuthenticationIsEnabled::class)
        ->and($panel->getAuthMiddleware())->toContain(EnsureAdminTwoFactorIsConfigured::class)
        ->and(Route::has('filament.admin.auth.multi-factor-authentication.set-up-required'))->toBeTrue();
})->with(['admins', 'all']);

it('administrador sem o segundo fator: toda tela do painel leva à configuração, guardando o /admin como destino', function (): void {
    $this->bootWith(['auth.two_factor.required' => 'admins']);
    adminTfaDeclareSetupRoute();

    $admin = $this->admin();

    foreach (['/admin', '/admin/users', '/admin/audit-events', '/admin/profile', '/admin/settings'] as $tela) {
        $this->actingAs($admin)->get($tela)->assertRedirect(route('two-factor.setup'));
        expect(session('url.intended'))->toBe(url($tela));
    }

    // A rota "configurar o MFA exigido" do Filament também leva para lá.
    $this->actingAs($admin)->get(route('filament.admin.auth.multi-factor-authentication.set-up-required'))
        ->assertRedirect(route('two-factor.setup'));

    $this->get('/two-factor/setup')->assertOk()->assertSee('tela de configuração do segundo fator');
});

it('administrador sem o segundo fator: ação Livewire de uma página já aberta também é recusada', function (): void {
    $this->bootWith(['auth.two_factor.required' => 'admins']);
    adminTfaDeclareSetupRoute();

    $admin = $this->admin(['name' => 'Nome Original', 'two_factor_enabled_at' => now()]);

    $html = $this->actingAs($admin)->get('/admin/profile')->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, Profile::class);

    // O segundo fator saiu por fora (suporte) com a página aberta.
    $admin->forceFill(['two_factor_enabled_at' => null])->save();

    $resposta = $this->livewireCall($snapshot, 'save', ['data.name' => 'Alterado']);

    expect($resposta->getStatusCode())->toBeIn([302, 403]);
    expect($admin->fresh()->name)->toBe('Nome Original');
});

it('sem a tela de configuração no front, o painel recusa (403) — nunca deixa passar', function (): void {
    $this->bootWith(['auth.two_factor.required' => 'all']);

    $this->actingAs($this->admin())->get('/admin/users')->assertForbidden();
});

it('administrador com o segundo fator ligado entra normalmente', function (): void {
    $this->bootWith(['auth.two_factor.required' => 'admins']);
    adminTfaDeclareSetupRoute();

    $this->actingAs($this->admin(['two_factor_enabled_at' => now()]))->get('/admin/users')->assertOk();
});

it('carência: administrador que já existia opera até o prazo', function (): void {
    $this->bootWith([
        'auth.two_factor.required' => 'admins',
        'auth.two_factor.grace_days' => 7,
        'auth.two_factor.required_since' => now()->subDay()->toDateString(),
    ]);
    adminTfaDeclareSetupRoute();

    $admin = $this->admin();
    $admin->forceFill(['created_at' => now()->subMonth()])->save();

    $this->actingAs($admin)->get('/admin/users')->assertOk();

    $this->travel(7)->days();

    $this->actingAs($admin)->get('/admin/users')->assertRedirect(route('two-factor.setup'));
});

it('"administrador" é quem entra no painel OU tem qualquer papel dele', function (): void {
    expect(app(IdentifiesAdministrators::class))->toBeInstanceOf(PanelAdministrators::class);

    $criterio = new PanelAdministrators;

    expect($criterio->isAdministrator(User::fixture(['email' => 'comum@example.com'])))->toBeFalse()
        ->and($criterio->isAdministrator(User::fixture(['email' => 'flag@example.com', 'is_admin' => true])))->toBeTrue();

    foreach (['owner', 'operations', 'support', 'auditor'] as $papel) {
        expect($criterio->isAdministrator(User::fixture(['email' => "{$papel}@example.com", 'admin_role' => $papel])))->toBeTrue();
    }
});

it('`admins` no painel do cliente: o administrador é levado à configuração; a conta comum, não', function (): void {
    $this->bootWith(['auth.two_factor.required' => 'admins']);
    adminTfaDeclareSetupRoute();
    Route::middleware('web')->get('painel-teste', fn (): string => 'painel do cliente');

    $this->actingAs(User::fixture(['email' => 'comum@example.com']))->get('/painel-teste')->assertOk();
    $this->actingAs(User::fixture(['email' => 'suporte@example.com', 'is_admin' => true, 'admin_role' => 'support']))
        ->get('/painel-teste')
        ->assertRedirect(route('two-factor.setup'));
});

it('perfil do /admin: com a regra valendo, desligar fica indisponível e a recusa no servidor vai uma vez à trilha', function (): void {
    $admin = $this->admin(['transaction_password' => 'Trans4cao!Segura', 'two_factor_enabled_at' => now()]);
    $this->actingAs($admin);

    // Com a regra: o botão vem desabilitado, com o motivo.
    config()->set('auth.two_factor.required', 'admins');

    Livewire::test(Profile::class)
        ->assertSee(__('auth.two_factor.required_cannot_disable'))
        ->assertActionDisabled('toggleTwoFactor');

    // A regra entra com a confirmação já aberta (o botão estava ativo): o
    // servidor recusa no último passo, sem desligar, e registra uma vez.
    config()->set('auth.two_factor.required', 'none');

    $componente = Livewire::test(Profile::class)
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'Trans4cao!Segura'])
        ->assertActionMounted('confirmTwoFactor');

    config()->set('auth.two_factor.required', 'admins');

    $componente->setActionData(['code' => adminTfaCode()])
        ->callMountedAction()
        ->assertNotified(__('auth.two_factor.required_cannot_disable'));

    expect($admin->fresh()->two_factor_enabled_at)->not->toBeNull();

    $recusas = AuditEvent::query()->where('action', 'user.two_factor_disabled')->get();

    expect($recusas)->toHaveCount(1)
        ->and($recusas->first()->outcome)->toBe(AuditOutcome::Denied)
        ->and($recusas->first()->context->value)->toBe('admin')
        ->and($recusas->first()->subject_uuid)->toBe($admin->uuid)
        ->and($recusas->first()->reason)->toBe(__('auth.two_factor.required_cannot_disable'));
});

it('o plugin declara a barreira na pilha persistente, entre o acesso e o modo sistema', function (): void {
    expect(AdminPlugin::authMiddleware())->toBe([
        Authenticate::class,
        EnsureAdminPanelAccess::class,
        EnsureAdminTwoFactorIsConfigured::class,
        OperateAdminPanelAsSystem::class,
    ]);
});
