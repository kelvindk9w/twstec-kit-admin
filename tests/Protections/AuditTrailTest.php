<?php

declare(strict_types=1);

use Filament\Support\Exceptions\Cancel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\CreateUser;
use Twstec\Kit\Admin\Resources\Users\Pages\EditUser;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Tests\Fixtures\ProtectedAccounts;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Contracts\AccountProtection;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// TRILHA DE AUDITORIA DO PAINEL numa aplicação limpa (requisito do dono):
// TODA escrita feita pelo painel fica gravada NO BANCO (`audit_events`) com
// quem agiu, o registro, o antes/depois redigido, IP, User-Agent e
// correlation_id; as recusas das guardas ficam como `denied`; e a trilha
// FALHA FECHADA — sem a linha dela, a mudança é desfeita.
//
// Nenhuma linha de auditoria no aplicativo: quem liga a captura é o pacote
// (AdminAudit, no boot do provider) e quem põe as ações em transação é o
// plugin (com a garantia do AdminPanelHardening).
// =============================================================================

beforeEach(function (): void {
    // Um segundo admin garante que o ator nunca é o "último admin ativo".
    $this->outroAdmin = $this->admin(['email' => 'outro-admin@example.com']);
    $this->operador = $this->admin(['email' => 'operador@example.com']);
    $this->actingAs($this->operador);
});

function adminAuditRow(string $action, ?string $subjectUuid = null): AuditEvent
{
    return AuditEvent::query()
        ->where('action', $action)
        ->when($subjectUuid !== null, fn ($q) => $q->where('subject_uuid', $subjectUuid))
        ->sole();
}

it('CRIAR usuário pelo painel grava user.created: quem, qual registro e o retrato redigido', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana Beatriz Nova',
            'email' => 'ana.nova@example.com',
            'password' => 'Senha-Forte123',
            'password_confirmation' => 'Senha-Forte123',
            'status' => UserStatus::Active->value,
            'is_admin' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ana.nova@example.com')->sole();
    $evento = adminAuditRow('user.created', $user->uuid);
    $bruto = json_encode($evento->getAttributes());

    expect($evento->outcome)->toBe(AuditOutcome::Success)
        ->and($evento->context)->toBe(AuditContext::Admin)
        ->and($evento->actor_uuid)->toBe($this->operador->uuid)
        ->and($evento->subject_type)->toBe('user')
        ->and($evento->changes['name'])->toBe(['before' => null, 'after' => 'A*** B*** N***'])
        ->and($evento->changes['email']['after'])->toBe('a***@example.com')
        ->and($evento->changes['password'])->toBe(['before' => null, 'after' => '[REDACTED]'])
        ->and($evento->changes['status']['after'])->toBe('active')
        ->and($bruto)->not->toContain('Senha-Forte123')
        ->and($bruto)->not->toContain('ana.nova@example.com')
        ->and($bruto)->not->toContain('Ana Beatriz');
});

it('EDITAR usuário pelo painel grava user.updated só com o que mudou', function (): void {
    $alvo = User::fixture(['name' => 'Carlos Antigo']);

    Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->fillForm(['name' => 'Carlos Novo', 'password' => 'Outra-Senha456', 'password_confirmation' => 'Outra-Senha456'])
        ->call('save')
        ->assertHasNoFormErrors();

    $evento = adminAuditRow('user.updated', $alvo->uuid);

    expect($alvo->fresh()->name)->toBe('Carlos Novo')
        ->and(array_keys($evento->changes))->toEqualCanonicalizing(['name', 'password'])
        ->and($evento->changes['password'])->toBe(['before' => '[REDACTED]', 'after' => '[REDACTED]'])
        ->and($evento->actor_uuid)->toBe($this->operador->uuid)
        ->and(json_encode($evento->getAttributes()))->not->toContain('Outra-Senha456');
});

it('BLOQUEAR e desbloquear pelo painel gravam user.blocked e user.unblocked com o de/para', function (): void {
    $alvo = User::fixture();

    Livewire::test(ListUsers::class)->callTableAction('block', $alvo);
    Livewire::test(ListUsers::class)->callTableAction('unblock', $alvo->fresh());

    expect(adminAuditRow('user.blocked', $alvo->uuid)->changes['status'])->toBe(['before' => 'active', 'after' => 'blocked'])
        ->and(adminAuditRow('user.unblocked', $alvo->uuid)->changes['status'])->toBe(['before' => 'blocked', 'after' => 'active']);
});

it('EXCLUIR usuário pelo painel grava user.deleted com o retrato redigido do que saiu', function (): void {
    $alvo = User::fixture(['email' => 'sai@example.com']);

    Livewire::test(ListUsers::class)->callTableAction('delete', $alvo);

    $evento = adminAuditRow('user.deleted', $alvo->uuid);

    expect(User::query()->whereKey($alvo->id)->exists())->toBeFalse()
        ->and($evento->changes['email'])->toBe(['before' => 's***@example.com', 'after' => null])
        ->and($evento->changes['password']['before'])->toBe('[REDACTED]');
});

it('RECUSA da guarda (bloquear a si mesmo, excluir a si mesmo, tirar o próprio acesso) fica como denied, sem mudar nada', function (): void {
    Livewire::test(ListUsers::class)->callTableAction('block', $this->operador);

    Livewire::test(EditUser::class, ['record' => $this->operador->uuid])
        ->fillForm(['status' => UserStatus::Blocked->value])
        ->call('save');

    // Excluir a si mesmo: a ação some da tela; a guarda `before` é a segunda
    // barreira — exercitada direto, como faria uma chamada forjada.
    try {
        UserResource::deleteAction()->record($this->operador)->callBefore();
    } catch (Cancel) {
        // cancel() é o fluxo esperado da recusa.
    }

    $bloqueio = adminAuditRow('user.blocked', $this->operador->uuid);
    $edicao = adminAuditRow('user.updated', $this->operador->uuid);
    $exclusao = adminAuditRow('user.deleted', $this->operador->uuid);

    expect($bloqueio->outcome)->toBe(AuditOutcome::Denied)
        ->and($bloqueio->reason)->toBe(__('admin.users.cannot_block_self'))
        ->and($bloqueio->actor_uuid)->toBe($this->operador->uuid)
        ->and($edicao->outcome)->toBe(AuditOutcome::Denied)
        ->and($exclusao->outcome)->toBe(AuditOutcome::Denied)
        ->and($exclusao->reason)->toBe(__('admin.users.cannot_delete_self'))
        ->and($this->operador->fresh()->status)->toBe(UserStatus::Active)
        ->and(User::query()->whereKey($this->operador->id)->exists())->toBeTrue();
});

it('FALHA FECHADA: se a linha da trilha não pode ser gravada, a edição feita pelo painel é desfeita', function (): void {
    $alvo = User::fixture(['name' => 'Nome Original']);

    Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
        throw new RuntimeException('banco da trilha indisponível');
    });

    // A segunda camada (arquivo) registra a ação que não pôde ser gravada.
    $linhas = new ArrayObject;
    $canal = Mockery::mock();
    $canal->shouldIgnoreMissing();
    $canal->shouldReceive('error')->andReturnUsing(function (string $mensagem, array $contexto = []) use ($linhas): void {
        $linhas[] = ['message' => $mensagem, 'context' => $contexto];
    });
    Log::shouldReceive('channel')->with('request_log')->andReturn($canal);
    Log::getFacadeRoot()->shouldIgnoreMissing();

    expect(fn () => Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->fillForm(['name' => 'Nome Alterado'])
        ->call('save'))->toThrow(RuntimeException::class, 'banco da trilha indisponível');

    $falha = collect($linhas)->firstWhere('message', AuditTrail::PERSIST_FAILED_MESSAGE);

    expect($alvo->fresh()->name)->toBe('Nome Original')
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and($falha['context']['action'] ?? null)->toBe('user.updated')
        ->and($falha['context']['subject_uuid'] ?? null)->toBe($alvo->uuid);
});

it('FALHA FECHADA também nas Actions da tabela: bloqueio e exclusão sem a linha da trilha são desfeitos', function (): void {
    $bloqueado = User::fixture();
    $excluido = User::fixture();

    Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
        throw new RuntimeException('banco da trilha indisponível');
    });
    Log::shouldReceive('channel')->andReturn(tap(Mockery::mock())->shouldIgnoreMissing());
    Log::getFacadeRoot()->shouldIgnoreMissing();

    expect(fn () => Livewire::test(ListUsers::class)->callTableAction('block', $bloqueado))->toThrow(RuntimeException::class)
        ->and(fn () => Livewire::test(ListUsers::class)->callTableAction('delete', $excluido))->toThrow(RuntimeException::class);

    expect($bloqueado->fresh()->status)->toBe(UserStatus::Active)
        ->and(User::query()->whereKey($excluido->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('pelo endpoint real: IP, User-Agent e correlation_id da requisição na linha', function (): void {
    $alvo = User::fixture(['name' => 'Pelo Endpoint']);

    $html = $this->withHeaders(['User-Agent' => 'NavegadorDoOperador/1.0'])
        ->get('/admin/users/'.$alvo->uuid.'/edit')
        ->assertOk()
        ->getContent();

    $snapshot = $this->snapshotFrom((string) $html, EditUser::class);

    $this->livewireCall($snapshot, 'save', ['data.name' => 'Nome Pelo Endpoint'], ip: '198.51.100.23', server: ['HTTP_USER_AGENT' => 'NavegadorDoOperador/1.0'])
        ->assertOk();

    $evento = adminAuditRow('user.updated', $alvo->uuid);

    expect($alvo->fresh()->name)->toBe('Nome Pelo Endpoint')
        ->and($evento->ip)->toBe('198.51.100.23')
        ->and($evento->user_agent)->toBe('NavegadorDoOperador/1.0')
        ->and($evento->correlation_id)->not->toBeNull()
        ->and($evento->actor_uuid)->toBe($this->operador->uuid);
});

// -----------------------------------------------------------------------------
// Contas protegidas (contrato AccountProtection do twstec/kit-auth)
// -----------------------------------------------------------------------------

it('conta PROTEGIDA por extensão: excluir e editar somem da tela; bloquear, excluir e editar pelo servidor são recusados como denied', function (): void {
    $protegida = User::fixture(['email' => 'protegida@example.com']);
    app()->instance(AccountProtection::class, new ProtectedAccounts(['protegida@example.com']));

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('delete', $protegida)
        ->assertTableActionHidden('edit', $protegida)
        // Bloquear aparece (a conta está ativa) e é recusado no servidor.
        ->callTableAction('block', $protegida);

    // A guarda do servidor não depende do botão escondido.
    Livewire::test(EditUser::class, ['record' => $protegida->uuid])
        ->fillForm(['status' => UserStatus::Blocked->value])
        ->call('save');

    try {
        UserResource::deleteAction()->record($protegida)->callBefore();
    } catch (Cancel) {
        // cancel() é o fluxo esperado da recusa.
    }

    $bloqueio = adminAuditRow('user.blocked', $protegida->uuid);
    $edicao = adminAuditRow('user.updated', $protegida->uuid);

    expect($bloqueio->outcome)->toBe(AuditOutcome::Denied)
        ->and($bloqueio->reason)->toBe(__('admin.users.account_protected'))
        ->and($edicao->outcome)->toBe(AuditOutcome::Denied)
        ->and($protegida->fresh()->status)->toBe(UserStatus::Active)
        ->and(User::query()->whereKey($protegida->id)->exists())->toBeTrue()
        ->and(adminAuditRow('user.deleted', $protegida->uuid)->outcome)->toBe(AuditOutcome::Denied);
});

it('user:make-admin recusa promover ou rebaixar conta protegida, e registra a recusa', function (): void {
    $protegida = User::fixture(['email' => 'protegida-cli@example.com']);
    app()->instance(AccountProtection::class, new ProtectedAccounts(['protegida-cli@example.com']));

    $this->artisan('user:make-admin', ['email' => 'protegida-cli@example.com'])->assertFailed();

    $evento = AuditEvent::query()->where('action', 'user.admin_granted')->sole();

    expect($protegida->fresh()->is_admin)->toBeFalse()
        ->and($evento->outcome)->toBe(AuditOutcome::Denied)
        ->and($evento->context)->toBe(AuditContext::Console);

    // Conta comum: promove, com a linha de sucesso.
    $comum = User::fixture(['email' => 'comum-cli@example.com']);
    $this->artisan('user:make-admin', ['email' => 'comum-cli@example.com'])->assertSuccessful();

    expect($comum->fresh()->is_admin)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'user.admin_granted')->where('outcome', AuditOutcome::Success->value)->where('subject_uuid', $comum->uuid)->exists())->toBeTrue();
});
