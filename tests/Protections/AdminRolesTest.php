<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Admin\AdminServiceProvider;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Authorization\AdminRoles;
use Twstec\Kit\Admin\Pages\Settings;
use Twstec\Kit\Admin\Resources\Users\Pages\EditUser;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\Pages\ViewUser;
use Twstec\Kit\Admin\Resources\Users\Support\UserAdminGuard;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// PAPÉIS E PERMISSÕES DO /admin numa aplicação limpa (issue #21).
//
// - Entrar continua sendo `is_admin` + conta ativa; o QUE se faz lá dentro
//   vem do papel (`admin_role`), com a permissão conferida NO SERVIDOR em
//   toda chamada Livewire — a chamada forjada a uma ação sem permissão recebe
//   403 e deixa a linha `denied` na trilha, e nada muda.
// - Atribuir/retirar papel é ação sensível (senha de transação + código),
//   auditada, e sem escalada: ninguém concede o que não tem, ninguém muda o
//   próprio papel, ninguém mexe em quem está acima, o último dono não cai.
// - A migração dá o papel de dono a quem já era admin (ninguém perde acesso).
// =============================================================================

beforeEach(function (): void {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);

    // Um dono a mais: o ator dos testes nunca é o último.
    $this->dono = $this->admin(['email' => 'dono@example.com']);
});

/**
 * Admin ativo com o papel dado.
 */
function adminWithRole(?string $role, array $attributes = []): User
{
    return User::fixture(['is_admin' => true, 'admin_role' => $role, ...$attributes]);
}

/**
 * Token de ação sensível de verdade: senha de transação → código por e-mail
 * → token (o mesmo caminho da tela).
 */
function sensitiveTokenFor(User $user): string
{
    $user->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();

    $service = app(SensitiveActionService::class);
    $service->sendCode($user, 'Trans4cao!Segura');

    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::SensitiveAction)
        ->last();

    return $service->confirmCode($user, $mail->code)['token'];
}

/**
 * Troca a pessoa logada (sessão nova — a proteção de sessão do Filament
 * derruba quem aparece com o hash de senha de outra conta).
 */
function actAs(mixed $test, User $user): void
{
    $test->flushSession();
    $test->actingAs($user);
}

function deniedRow(string $action, ?string $subjectUuid = null): ?AuditEvent
{
    return AuditEvent::query()
        ->where('action', $action)
        ->where('outcome', AuditOutcome::Denied->value)
        ->when($subjectUuid !== null, fn ($q) => $q->where('subject_uuid', $subjectUuid))
        ->latest('id')
        ->first();
}

// -----------------------------------------------------------------------------
// Matriz de permissões NO SERVIDOR (chamadas Livewire reais ao endpoint).
// -----------------------------------------------------------------------------

it('MATRIZ: cada papel monta só as ações que o papel dá — o resto é 403 com `denied` na trilha', function (string $role, string $action, bool $allowed): void {
    $actor = adminWithRole('owner');
    $alvo = User::fixture(['email' => 'alvo@example.com', 'email_verified_at' => null]);

    // A tela é aberta com um papel que mostra tudo (o snapshot é legítimo e
    // assinado); a chamada sai com o papel em teste — como quem forja o
    // pedido de um botão que, para ele, nem aparece.
    $html = $this->actingAs($actor)->get('/admin/users')->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, ListUsers::class);

    $actor->forceFill(['admin_role' => $role])->save();

    $resposta = $this->livewireCall($snapshot, 'mountAction', [], [$action, [], ['table' => true, 'recordKey' => (string) $alvo->getKey()]]);

    if ($allowed) {
        $resposta->assertOk();
        expect(AuditEvent::query()->where('outcome', AuditOutcome::Denied->value)->count())->toBe(0);

        return;
    }

    $resposta->assertForbidden();

    $linha = AuditEvent::query()->where('outcome', AuditOutcome::Denied->value)->sole();

    expect($linha->actor_uuid)->toBe($actor->uuid)
        ->and($linha->subject_uuid)->toBe($alvo->uuid)
        ->and($linha->reason)->toContain('users.')
        ->and($alvo->fresh()->status)->toBe(UserStatus::Active);
})->with([
    'suporte bloqueia' => ['support', 'block', false],
    'suporte exclui' => ['support', 'delete', false],
    'suporte edita' => ['support', 'edit', false],
    'suporte marca e-mail verificado' => ['support', 'markEmailVerified', true],
    'auditor marca e-mail verificado' => ['auditor', 'markEmailVerified', false],
    'auditor bloqueia' => ['auditor', 'block', false],
    'auditor vê' => ['auditor', 'view', true],
    'operação bloqueia' => ['operations', 'block', true],
    'operação exclui' => ['operations', 'delete', true],
    'dono bloqueia' => ['owner', 'block', true],
]);

it('ação forjada SEM permissão não executa: o callMountedAction de quem perdeu o papel é 403 e o alvo não muda', function (): void {
    $operador = adminWithRole('operations');
    $alvo = User::fixture(['email' => 'alvo-bloqueio@example.com']);

    // Com o papel, a pessoa abre a tela e monta o "Bloquear".
    $html = $this->actingAs($operador)->get('/admin/users')->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, ListUsers::class);
    $montado = $this->livewireCall($snapshot, 'mountAction', [], ['block', [], ['table' => true, 'recordKey' => (string) $alvo->getKey()]])->assertOk();
    $snapshotMontado = (string) $montado->json('components.0.snapshot');

    // Perdeu o papel com o modal aberto.
    $operador->forceFill(['admin_role' => 'support'])->save();

    $this->livewireCall($snapshotMontado, 'callMountedAction')->assertForbidden();

    expect($alvo->fresh()->status)->toBe(UserStatus::Active)
        ->and(deniedRow('user.blocked', $alvo->uuid))->not->toBeNull();
});

it('papel sem `update` não salva a edição (403), mesmo com o snapshot da tela aberto antes', function (): void {
    $operador = adminWithRole('operations');
    $alvo = User::fixture(['name' => 'Nome Original']);

    $html = $this->actingAs($operador)->get("/admin/users/{$alvo->uuid}/edit")->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, EditUser::class);

    $operador->forceFill(['admin_role' => 'auditor'])->save();

    $this->livewireCall($snapshot, 'save', ['data.name' => 'Alterado'])->assertForbidden();

    expect($alvo->fresh()->name)->toBe('Nome Original')
        ->and(deniedRow('user.updated', $alvo->uuid))->not->toBeNull();
});

it('telas: o papel abre só o que dá; sem papel, só os dashboards', function (): void {
    $auditor = adminWithRole('auditor');
    $suporte = adminWithRole('support');
    $semPapel = adminWithRole(null);
    $alvo = User::fixture();

    actAs($this, $auditor);
    $this->get('/admin/users')->assertOk();
    $this->get('/admin/users/create')->assertForbidden();
    $this->get("/admin/users/{$alvo->uuid}/edit")->assertForbidden();
    $this->get('/admin/audit-events')->assertOk();
    $this->get('/admin/settings')->assertOk();

    actAs($this, $suporte);
    $this->get('/admin/users')->assertOk();
    $this->get('/admin/audit-events')->assertForbidden();
    $this->get('/admin/settings')->assertForbidden();

    actAs($this, $semPapel);
    $this->get('/admin')->assertOk();
    $this->get('/admin/profile')->assertOk();
    $this->get('/admin/users')->assertForbidden();

    // Papel que não existe mais na config: nenhuma permissão.
    actAs($this, adminWithRole('papel-removido'));
    $this->get('/admin/users')->assertForbidden();
});

it('Configurações: quem só vê não salva — 403 no servidor e `denied` na trilha', function (): void {
    $operador = adminWithRole('owner');

    $html = $this->actingAs($operador)->get('/admin/settings')->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, Settings::class);

    // Passa a só ver (auditoria) com a tela aberta.
    $operador->forceFill(['admin_role' => 'auditor'])->save();

    $this->livewireCall($snapshot, 'save')->assertForbidden();

    expect(deniedRow('setting.updated')?->reason)->toBe(__('admin.authorization.denied', ['permission' => 'settings.update']));
});

it('esconder também: a tabela não mostra o que o papel não dá (conforto — a barreira é o servidor)', function (): void {
    $alvo = User::fixture(['email_verified_at' => null]);

    $this->actingAs(adminWithRole('support'));

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('block', $alvo)
        ->assertTableActionHidden('delete', $alvo)
        ->assertTableActionHidden('edit', $alvo)
        ->assertTableActionVisible('markEmailVerified', $alvo);

    expect(UserResource::canCreate())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Atribuição de papel: ação sensível, auditada, sem escalada.
// -----------------------------------------------------------------------------

it('atribuir papel pela tela: senha de transação → código → papel gravado e `user.role_changed` na trilha', function (): void {
    $alvo = User::fixture(['email' => 'promovida@example.com']);
    $this->dono->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();
    $this->actingAs($this->dono);

    $tela = Livewire::test(ViewUser::class, ['record' => $alvo->uuid])
        ->callAction('assignRole', data: ['role' => 'support', 'transaction_password' => 'Trans4cao!Segura'])
        ->assertActionMounted('confirmAssignRole');

    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)->last();

    $tela->setActionData(['code' => $mail->code])->callMountedAction()->assertHasNoActionErrors();

    $alvo->refresh();
    $linha = AuditEvent::query()->where('action', 'user.role_changed')->where('outcome', AuditOutcome::Success->value)->sole();

    expect($alvo->admin_role)->toBe('support')
        ->and($alvo->is_admin)->toBeTrue()
        ->and($linha->subject_uuid)->toBe($alvo->uuid)
        ->and($linha->actor_uuid)->toBe($this->dono->uuid)
        ->and($linha->changes['admin_role'])->toBe(['before' => null, 'after' => 'support'])
        ->and($linha->changes['is_admin'])->toBe(['before' => false, 'after' => true]);
});

it('atribuir papel SEM a confirmação sensível é recusado e registrado — o serviço é a barreira', function (): void {
    $alvo = User::fixture();

    expect(fn () => app(AdminRoles::class)->assign($this->dono, $alvo, 'support', null))->toThrow(RecordedDenial::class)
        ->and(fn () => app(AdminRoles::class)->assign($this->dono, $alvo, 'support', 'token-inventado'))->toThrow(RecordedDenial::class);

    expect($alvo->fresh()->admin_role)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'user.role_changed')->where('outcome', AuditOutcome::Denied->value)->count())->toBe(2);
});

it('SEM ESCALADA: ninguém concede papel com permissão que não tem, nem muda quem está acima', function (): void {
    // Um papel de "gerente" que pode atribuir papel, mas não é dono.
    config()->set('admin.authorization.roles.manager', ['users.*', 'approvals.view']);

    $gerente = adminWithRole('manager');
    $alvo = User::fixture();
    $outroDono = $this->admin(['email' => 'outro-dono@example.com']);

    $tentar = function (User $target, ?string $role) use ($gerente): ?string {
        try {
            app(AdminRoles::class)->assign($gerente, $target, $role, sensitiveTokenFor($gerente));
        } catch (RecordedDenial $denial) {
            return $denial->getMessage();
        }

        return null;
    };

    expect($tentar($alvo, 'owner'))->toBe(__('admin.roles.cannot_grant'))
        // auditor = `*.view`: inclui telas que o gerente não vê.
        ->and($tentar($alvo, 'auditor'))->toBe(__('admin.roles.cannot_grant'))
        ->and($tentar($alvo, 'operations'))->toBe(__('admin.roles.cannot_grant'))
        // Rebaixar o dono: acima dele.
        ->and($tentar($outroDono, null))->toBe(__('admin.roles.cannot_change_higher'))
        // O próprio papel.
        ->and($tentar($gerente, 'owner'))->toBe(__('admin.roles.cannot_change_own'))
        // `manager` ⊆ o próprio gerente: pode.
        ->and($tentar($alvo, 'manager'))->toBeNull();

    expect($alvo->fresh()->admin_role)->toBe('manager')
        ->and($outroDono->fresh()->admin_role)->toBe('owner');
});

it('canGrant compara PADRÕES: `users.*` só quem tem `users.*` (ou `*`) concede', function (): void {
    config()->set('admin.authorization.roles.limited', ['users.view', 'users.update', 'users.assign_role']);
    config()->set('admin.authorization.roles.users_all', ['users.*']);
    config()->set('admin.authorization.roles.users_view', ['users.view']);

    $limitado = adminWithRole('limited');

    expect(AdminPermissions::canGrant($limitado, 'users_all'))->toBeFalse()
        ->and(AdminPermissions::canGrant($limitado, 'users_view'))->toBeTrue()
        ->and(AdminPermissions::canGrant($limitado, 'owner'))->toBeFalse()
        ->and(AdminPermissions::canGrant($this->dono, 'owner'))->toBeTrue()
        ->and(AdminPermissions::canGrant(adminWithRole('auditor'), 'support'))->toBeFalse();
});

it('ninguém muda o PRÓPRIO papel — nem o dono', function (): void {
    expect(fn () => app(AdminRoles::class)->assign($this->dono, $this->dono, 'support', sensitiveTokenFor($this->dono)))
        ->toThrow(RecordedDenial::class, __('admin.roles.cannot_change_own'));

    expect($this->dono->fresh()->admin_role)->toBe('owner');
});

it('o ÚLTIMO dono ativo não é bloqueado, excluído nem perde a entrada — mesmo havendo outros admins', function (): void {
    // Outro admin ativo existe (o "último admin" não se aplica): a regra que
    // segura é a do último DONO.
    $operador = adminWithRole('operations');
    $this->actingAs($operador);

    Livewire::test(ListUsers::class)->callTableAction('block', $this->dono);

    expect($this->dono->fresh()->status)->toBe(UserStatus::Active)
        ->and(deniedRow('user.blocked', $this->dono->uuid)?->reason)->toBe(__('admin.users.cannot_remove_last_admin'))
        ->and(UserAdminGuard::deleteDenial($this->dono, $operador))->toBe(__('admin.users.cannot_remove_last_admin'))
        ->and(UserAdminGuard::updateDenial($this->dono, ['is_admin' => false], $operador))->toBe(__('admin.users.cannot_remove_last_admin'))
        ->and(UserAdminGuard::updateDenial($this->dono, ['status' => UserStatus::Blocked->value], $operador))->toBe(__('admin.users.cannot_remove_last_admin'));

    // Com um segundo dono ativo, o primeiro deixa de ser o último.
    $this->admin(['email' => 'segundo-dono@example.com']);

    Livewire::test(ListUsers::class)->callTableAction('block', $this->dono);

    expect($this->dono->fresh()->status)->toBe(UserStatus::Blocked);
});

it('a flag de acesso no formulário: pede `users.assign_role`, e tirá-la tira também o papel', function (): void {
    $operador = adminWithRole('operations');
    $alvo = adminWithRole('support', ['email' => 'suporte@example.com']);

    // Operação não atribui papel: o campo vem desabilitado e, forjado, é recusado.
    $this->actingAs($operador);
    Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->assertFormFieldIsDisabled('is_admin');

    expect(UserAdminGuard::updateDenial($alvo, ['is_admin' => false], $operador))
        ->toBe(__('admin.authorization.denied', ['permission' => 'users.assign_role']));

    // O dono tira a entrada: o papel vai junto.
    $this->actingAs($this->dono);
    Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->fillForm(['is_admin' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($alvo->fresh()->is_admin)->toBeFalse()
        ->and($alvo->fresh()->admin_role)->toBeNull();

    // Devolver a entrada não devolve o papel antigo.
    Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->fillForm(['is_admin' => true])
        ->call('save');

    expect($alvo->fresh()->is_admin)->toBeTrue()
        ->and($alvo->fresh()->admin_role)->toBeNull();
});

it('quem não pode ver/editar o papel recebe a recusa também na ação sensível de papel (403 no servidor)', function (): void {
    $operador = adminWithRole('owner');
    $alvo = User::fixture();

    $html = $this->actingAs($operador)->get("/admin/users/{$alvo->uuid}")->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, ViewUser::class);

    $operador->forceFill(['admin_role' => 'support'])->save();
    $this->livewireCall($snapshot, 'mountAction', [], ['assignRole'])->assertForbidden();

    expect(deniedRow('user.role_changed'))->not->toBeNull();
});

// -----------------------------------------------------------------------------
// Migração e comando: ninguém perde acesso.
// -----------------------------------------------------------------------------

it('MIGRAÇÃO: quem já era is_admin vira dono; quem não era continua sem papel', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_01_100000_add_admin_role_to_users_table.php';

    $antigo = User::fixture(['is_admin' => true, 'email' => 'admin-antigo@example.com']);
    $comum = User::fixture(['email' => 'comum@example.com']);

    // Volta ao estado de antes da migration (sem a coluna) e roda de novo.
    $migration->down();
    expect(DB::getSchemaBuilder()->hasColumn('users', 'admin_role'))->toBeFalse();
    $migration->up();

    expect(DB::table('users')->where('email', 'admin-antigo@example.com')->value('admin_role'))->toBe('owner')
        ->and(DB::table('users')->where('email', 'dono@example.com')->value('admin_role'))->toBe('owner')
        ->and(DB::table('users')->where('email', 'comum@example.com')->value('admin_role'))->toBeNull();

    $this->actingAs($antigo->fresh())->get('/admin/users')->assertOk();
    expect($comum->fresh()->admin_role)->toBeNull();
});

it('user:make-admin dá o papel de dono (ou o de --role) e --remove tira os dois', function (): void {
    $pessoa = User::fixture(['email' => 'nova-admin@example.com']);

    Artisan::call('user:make-admin', ['email' => 'nova-admin@example.com']);
    expect($pessoa->fresh()->admin_role)->toBe('owner')->and($pessoa->fresh()->is_admin)->toBeTrue();

    Artisan::call('user:make-admin', ['email' => 'nova-admin@example.com', '--role' => 'support']);
    expect($pessoa->fresh()->admin_role)->toBe('support');

    expect(Artisan::call('user:make-admin', ['email' => 'nova-admin@example.com', '--role' => 'inexistente']))->toBe(1)
        ->and($pessoa->fresh()->admin_role)->toBe('support');

    Artisan::call('user:make-admin', ['email' => 'nova-admin@example.com', '--remove' => true]);
    expect($pessoa->fresh()->admin_role)->toBeNull()->and($pessoa->fresh()->is_admin)->toBeFalse();
});

// -----------------------------------------------------------------------------
// Opt-out explícito.
// -----------------------------------------------------------------------------

it('opt-out (ADMIN_AUTHORIZATION=false): volta o tudo-ou-nada e AVISA no log a cada boot', function (): void {
    $this->bootWith(['admin.authorization.enabled' => false]);

    Log::spy();
    (new AdminServiceProvider($this->app))->boot();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'ADMIN_AUTHORIZATION=false'))->atLeast()->once();

    $semPapel = adminWithRole(null);

    expect(AdminPermissions::allows($semPapel, 'users.delete'))->toBeTrue()
        ->and(AdminPermissions::allows(User::fixture(), 'users.view'))->toBeFalse();
});
