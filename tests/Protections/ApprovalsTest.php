<?php

declare(strict_types=1);

use Filament\Notifications\Livewire\Notifications as FilamentNotifications;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Admin\AdminServiceProvider;
use Twstec\Kit\Admin\Approvals\ApprovableAction;
use Twstec\Kit\Admin\Approvals\Approvals;
use Twstec\Kit\Admin\Approvals\ApprovalService;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalMode;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Approvals\Models\ApprovalRequest;
use Twstec\Kit\Admin\Resources\ApprovalRequests\ApprovalRequestResource;
use Twstec\Kit\Admin\Resources\ApprovalRequests\Pages\ViewApprovalRequest;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\Support\DeleteUserApproval;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// APROVAÇÃO EM DOIS PASSOS numa aplicação limpa (issue #21).
//
// O exemplo do kit é "excluir usuário" (DeleteUserApproval), ligado por
// config (`admin.approvals.actions`). As provas:
// - a ação vira PEDIDO pendente (quem, o quê, antes/depois redigido, motivo,
//   validade) e só executa aprovada;
// - QUATRO OLHOS: quem pediu não aprova o próprio pedido (recusa na trilha);
// - aprovar pede a ação sensível; sem ela, recusa;
// - vencido não aprova; estado mudado entre pedido e aprovação → obsoleto, nada
//   executa; executa UMA vez (a segunda aprovação encontra o pedido decidido);
// - falha na execução desfaz a execução e deixa o pedido `failed`;
// - MODO DE UM OPERADOR: o mesmo aprova só com a ação sensível e executa num
//   segundo passo, depois da espera mínima;
// - tudo na trilha: pedido, aprovação, recusa, execução, falha.
//
// A concorrência de verdade (duas aprovações ao mesmo tempo, processos
// separados, PostgreSQL) está no starter (ApprovalConcurrencyTest).
// =============================================================================

beforeEach(function (): void {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);
    config()->set('admin.approvals.actions', [DeleteUserApproval::KEY]);

    $this->pede = $this->admin(['email' => 'quem-pede@example.com']);
    $this->aprova = $this->admin(['email' => 'quem-aprova@example.com']);
    $this->alvo = User::fixture(['email' => 'alvo-exclusao@example.com', 'name' => 'Alvo Exclusao']);
});

/**
 * Token de ação sensível pelo caminho de verdade (senha de transação →
 * código por e-mail → token).
 */
function approvalToken(User $user): string
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

function requestDeletion(User $requester, User $target, string $reason = 'Conta duplicada, confirmada por telefone'): ApprovalRequest
{
    return app(ApprovalService::class)->request(DeleteUserApproval::KEY, $target, [], $reason, $requester);
}

/**
 * Recusa esperada: devolve a mensagem (e a linha `denied` já tem de existir).
 */
function refusal(callable $callback): string
{
    try {
        $callback();
    } catch (RecordedDenial $denial) {
        expect($denial->event->outcome)->toBe(AuditOutcome::Denied);

        return $denial->getMessage();
    }

    throw new RuntimeException('Esperava uma recusa e a operação passou.');
}

function auditActions(): array
{
    return AuditEvent::query()->orderBy('id')->get()
        ->map(fn (AuditEvent $e): string => $e->action.':'.$e->outcome->value)
        ->all();
}

// -----------------------------------------------------------------------------
// O pedido, pela tela.
// -----------------------------------------------------------------------------

it('com a aprovação ligada, EXCLUIR pela tela não exclui: cria o pedido pendente com motivo, antes/depois redigido e validade', function (): void {
    $this->actingAs($this->pede);

    Livewire::test(ListUsers::class)
        ->callTableAction('delete', $this->alvo, data: ['approval_reason' => 'Pedido do titular por e-mail alvo-exclusao@example.com'])
        ->assertHasNoTableActionErrors();

    $pedido = ApprovalRequest::query()->sole();
    $bruto = json_encode(DB::table('admin_approval_requests')->first());

    expect(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue()
        ->and($pedido->status)->toBe(ApprovalStatus::Pending)
        ->and($pedido->mode)->toBe(ApprovalMode::FourEyes)
        ->and($pedido->action)->toBe('users.delete')
        ->and($pedido->subject_type)->toBe('user')
        ->and($pedido->subject_uuid)->toBe($this->alvo->uuid)
        ->and($pedido->requested_by_uuid)->toBe($this->pede->uuid)
        ->and($pedido->expires_at->between(now()->addMinutes(1439), now()->addMinutes(1441)))->toBeTrue()
        ->and($pedido->summary['email'])->toBe(['before' => 'a***@example.com', 'after' => null])
        ->and($pedido->summary['name'])->toBe(['before' => 'A*** E***', 'after' => null])
        // O motivo também passa pela redação; o e-mail em claro não fica.
        ->and($pedido->reason)->not->toContain('alvo-exclusao@example.com')
        ->and($bruto)->not->toContain('alvo-exclusao@example.com')
        ->and($bruto)->not->toContain('Alvo Exclusao');

    $linha = AuditEvent::query()->where('action', 'approval_request.created')->sole();

    expect($linha->actor_uuid)->toBe($this->pede->uuid)
        ->and($linha->subject_uuid)->toBe($pedido->uuid)
        ->and($linha->changes['status']['after'])->toBe('pending')
        ->and($linha->changes['payload']['after'])->toBe('[REDACTED]');
});

it('sem motivo não há pedido (o formulário exige) — e o serviço também recusa, registrado', function (): void {
    $this->actingAs($this->pede);

    Livewire::test(ListUsers::class)
        ->callTableAction('delete', $this->alvo, data: ['approval_reason' => ''])
        ->assertHasTableActionErrors(['approval_reason' => 'required']);

    expect(refusal(fn () => requestDeletion($this->pede, $this->alvo, '   ')))->toBe(__('admin.approvals.reason_required'))
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('as guardas da ação valem já no PEDIDO: pedir a exclusão da própria conta é recusado', function (): void {
    expect(refusal(fn () => requestDeletion($this->pede, $this->pede)))->toBe(__('admin.users.cannot_delete_self'))
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'approval_request.created')->where('outcome', 'denied')->count())->toBe(1);
});

it('sem a aprovação ligada, o mesmo botão exclui direto (nada muda para quem não liga)', function (): void {
    config()->set('admin.approvals.actions', []);
    $this->actingAs($this->pede);

    Livewire::test(ListUsers::class)->callTableAction('delete', $this->alvo);

    expect(User::query()->whereKey($this->alvo->id)->exists())->toBeFalse()
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// Quatro olhos.
// -----------------------------------------------------------------------------

it('QUATRO OLHOS: quem pediu NÃO aprova o próprio pedido — nem com a ação sensível; a recusa fica na trilha', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);

    $motivo = refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->pede, approvalToken($this->pede)));

    expect($motivo)->toBe(__('admin.approvals.own_request'))
        ->and($pedido->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue();

    $linha = AuditEvent::query()->where('action', 'approval_request.approved')->sole();

    expect($linha->outcome)->toBe(AuditOutcome::Denied)
        ->and($linha->actor_uuid)->toBe($this->pede->uuid)
        ->and($linha->subject_uuid)->toBe($pedido->uuid)
        ->and($linha->reason)->toBe(__('admin.approvals.own_request'));

    // Na tela, o botão nem aparece para quem pediu.
    $this->actingAs($this->pede);
    Livewire::test(ViewApprovalRequest::class, ['record' => $pedido->uuid])->assertActionHidden('approve');
});

it('OUTRA pessoa aprova pela tela (senha de transação → código): executa uma vez, tudo na trilha', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);
    $this->aprova->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();
    $this->actingAs($this->aprova);

    $tela = Livewire::test(ViewApprovalRequest::class, ['record' => $pedido->uuid])
        ->assertActionVisible('approve')
        ->callAction('approve', data: ['transaction_password' => 'Trans4cao!Segura'])
        ->assertActionMounted('confirmApprove');

    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)->last();

    $tela->setActionData(['code' => $mail->code])->callMountedAction()->assertHasNoActionErrors();

    $pedido->refresh();

    expect(User::query()->whereKey($this->alvo->id)->exists())->toBeFalse()
        ->and($pedido->status)->toBe(ApprovalStatus::Executed)
        ->and($pedido->decided_by_uuid)->toBe($this->aprova->uuid)
        ->and($pedido->executed_by_uuid)->toBe($this->aprova->uuid)
        ->and($pedido->executed_at)->not->toBeNull();

    $porAprovador = AuditEvent::query()->where('actor_uuid', $this->aprova->uuid)->where('outcome', 'success')->pluck('action')->all();

    expect($porAprovador)->toContain('approval_request.approved', 'user.deleted', 'approval_request.executed')
        ->and(AuditEvent::query()->where('action', 'user.deleted')->sole()->subject_uuid)->toBe($this->alvo->uuid);

    // A segunda aprovação (outra aba, outra pessoa) encontra o pedido decidido.
    $terceiro = $this->admin(['email' => 'terceiro@example.com']);

    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $terceiro, approvalToken($terceiro))))
        ->toBe(__('admin.approvals.already_decided'))
        ->and(AuditEvent::query()->where('action', 'user.deleted')->count())->toBe(1);
});

it('aprovar SEM a ação sensível é recusado (o serviço é a barreira, não a tela)', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);

    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->aprova, null)))->toBe(__('admin.sensitive.required'))
        ->and(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->aprova, 'token-que-nao-existe')))->toBe(__('admin.sensitive.required'))
        ->and($pedido->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue();
});

it('aprovar exige `approvals.approve` E a permissão da própria ação', function (): void {
    config()->set('admin.authorization.roles.so_aprova', ['approvals.*']);

    $pedido = requestDeletion($this->pede, $this->alvo);
    $suporte = User::fixture(['is_admin' => true, 'admin_role' => 'support']);
    $soAprova = User::fixture(['is_admin' => true, 'admin_role' => 'so_aprova']);

    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $suporte, approvalToken($suporte))))
        ->toBe(__('admin.authorization.denied', ['permission' => 'approvals.approve']))
        ->and(refusal(fn () => app(ApprovalService::class)->approve($pedido, $soAprova, approvalToken($soAprova))))
        ->toBe(__('admin.authorization.denied', ['permission' => 'users.delete']))
        ->and($pedido->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('PEDIDO VENCIDO não é aprovado: fica `expired`, com linha na trilha', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);
    $token = approvalToken($this->aprova);

    $this->travel(1441)->minutes();

    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->aprova, $token)))->toBe(__('admin.approvals.expired'))
        ->and($pedido->fresh()->status)->toBe(ApprovalStatus::Expired)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'approval_request.expired')->where('outcome', 'success')->count())->toBe(1);
});

it('ESTADO MUDADO entre o pedido e a aprovação: o pedido fica obsoleto e nada executa', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);

    // Depois do pedido, a conta foi bloqueada (outra decisão, outro contexto).
    $this->alvo->forceFill(['status' => UserStatus::Blocked])->save();

    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->aprova, approvalToken($this->aprova))))->toBe(__('admin.approvals.stale'))
        ->and($pedido->fresh()->status)->toBe(ApprovalStatus::Stale)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'approval_request.stale')->count())->toBe(1);

    // Obsoleto é final: nem outra tentativa passa.
    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->aprova, approvalToken($this->aprova))))->toBe(__('admin.approvals.already_decided'));
});

it('as guardas valem de novo na APROVAÇÃO, com quem aprova: ninguém aprova a exclusão da própria conta', function (): void {
    $pedido = requestDeletion($this->pede, $this->aprova);

    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->aprova, approvalToken($this->aprova))))
        ->toBe(__('admin.users.cannot_delete_self'))
        ->and(User::query()->whereKey($this->aprova->id)->exists())->toBeTrue();
});

it('RECUSAR com motivo: pedido `rejected`, nada executa, e não dá mais para aprovar', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);

    expect(refusal(fn () => app(ApprovalService::class)->reject($pedido, $this->aprova, '')))->toBe(__('admin.approvals.reason_required'));

    app(ApprovalService::class)->reject($pedido, $this->aprova, 'Sem confirmação do titular');

    expect($pedido->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and($pedido->fresh()->decision_reason)->toBe('Sem confirmação do titular')
        ->and(AuditEvent::query()->where('action', 'approval_request.rejected')->where('outcome', 'success')->count())->toBe(1)
        ->and(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->aprova, approvalToken($this->aprova))))->toBe(__('admin.approvals.already_decided'))
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue();
});

it('FALHA na execução: o que ela fez é desfeito, o pedido fica `failed` (mensagem redigida) e a falha vai para a trilha', function (): void {
    Approvals::register(new class extends ApprovableAction
    {
        public function key(): string
        {
            return 'users.rename_and_fail';
        }

        public function subjectModel(): string
        {
            return User::class;
        }

        public function execute(Model $subject, array $data, Authenticatable $actor): void
        {
            $subject->forceFill(['name' => 'Renomeado'])->save();

            throw new RuntimeException('serviço externo recusou o titular titular@example.com');
        }
    });

    config()->set('admin.authorization.roles.owner', ['*']);

    $pedido = app(ApprovalService::class)->request('users.rename_and_fail', $this->alvo, [], 'Teste de falha', $this->pede);
    $resultado = app(ApprovalService::class)->approve($pedido, $this->aprova, approvalToken($this->aprova));

    expect($resultado->status)->toBe(ApprovalStatus::Failed)
        ->and($resultado->failure_reason)->toContain('RuntimeException')
        ->and($resultado->failure_reason)->not->toContain('titular@example.com')
        ->and($this->alvo->fresh()->name)->toBe('Alvo Exclusao')
        ->and(AuditEvent::query()->where('action', 'approval_request.failed')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'user.rename_and_fail')->count() + AuditEvent::query()->where('action', 'user.updated')->where('subject_uuid', $this->alvo->uuid)->count())->toBe(0);
});

it('PRIMITIVA GENÉRICA: uma ação do aplicativo registrada exige aprovação só quando listada', function (): void {
    $acao = Approvals::register(new class extends ApprovableAction
    {
        public function key(): string
        {
            return 'users.rename';
        }

        public function subjectModel(): string
        {
            return User::class;
        }

        public function changes(Model $subject, array $data): array
        {
            return ['name' => ['before' => $subject->getAttribute('name'), 'after' => $data['name']]];
        }

        public function execute(Model $subject, array $data, Authenticatable $actor): void
        {
            $subject->forceFill(['name' => $data['name']])->save();
        }
    });

    expect(Approvals::requires('users.rename'))->toBeFalse();
    config()->set('admin.approvals.actions', ['users.rename']);
    expect(Approvals::requires('users.rename'))->toBeTrue()
        ->and($acao->verb())->toBe('rename');

    $pedido = app(ApprovalService::class)->request('users.rename', $this->alvo, ['name' => 'Nome Novo'], 'Correção cadastral', $this->pede);

    expect($pedido->summary['name'])->toBe(['before' => 'A*** E***', 'after' => 'N*** N***'])
        ->and(DB::table('admin_approval_requests')->value('payload'))->not->toContain('Nome Novo');

    app(ApprovalService::class)->approve($pedido, $this->aprova, approvalToken($this->aprova));

    expect($this->alvo->fresh()->name)->toBe('Nome Novo')
        ->and(AuditEvent::query()->where('action', 'user.rename')->sole()->actor_uuid)->toBe($this->aprova->uuid);
});

// -----------------------------------------------------------------------------
// Modo de um operador.
// -----------------------------------------------------------------------------

it('UM OPERADOR: o mesmo aprova, só com a ação sensível, e executa num segundo passo depois da espera mínima', function (): void {
    config()->set('admin.approvals.mode', 'single_operator');
    config()->set('admin.approvals.single_operator.min_wait_minutes', 15);
    // Mesmo com a confirmação desligada para o modo quatro olhos, aqui ela vale.
    config()->set('admin.approvals.sensitive_confirmation', false);

    $pedido = requestDeletion($this->pede, $this->alvo);

    expect($pedido->mode)->toBe(ApprovalMode::SingleOperator)
        // Sem ação sensível: recusado.
        ->and(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->pede, null)))->toBe(__('admin.sensitive.required'));

    $aprovado = app(ApprovalService::class)->approve($pedido, $this->pede, approvalToken($this->pede));

    expect($aprovado->status)->toBe(ApprovalStatus::Approved)
        ->and($aprovado->executable_after->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue()
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue();

    // Antes da espera: recusado, com linha na trilha.
    $this->travel(10)->minutes();
    expect(refusal(fn () => app(ApprovalService::class)->execute($pedido, $this->pede)))->toStartWith(substr(__('admin.approvals.wait', ['time' => '']), 0, 20))
        ->and(AuditEvent::query()->where('action', 'approval_request.executed')->where('outcome', 'denied')->count())->toBe(1)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue();

    // Depois da espera: executa, uma vez.
    $this->travel(6)->minutes();
    $executado = app(ApprovalService::class)->execute($pedido, $this->pede);

    expect($executado->status)->toBe(ApprovalStatus::Executed)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeFalse()
        ->and(refusal(fn () => app(ApprovalService::class)->execute($pedido, $this->pede)))->toBe(__('admin.approvals.not_approved'));
});

it('UM OPERADOR: a execução também vence (janela) e reconfere o estado', function (): void {
    config()->set('admin.approvals.mode', 'single_operator');
    config()->set('admin.approvals.single_operator.min_wait_minutes', 1);
    config()->set('admin.approvals.single_operator.execution_window_minutes', 60);

    $pedido = requestDeletion($this->pede, $this->alvo);
    app(ApprovalService::class)->approve($pedido, $this->pede, approvalToken($this->pede));

    $this->travel(62)->minutes();

    expect(refusal(fn () => app(ApprovalService::class)->execute($pedido, $this->pede)))->toBe(__('admin.approvals.expired'))
        ->and($pedido->fresh()->status)->toBe(ApprovalStatus::Expired);

    $outro = User::fixture(['email' => 'outro-alvo@example.com']);
    $segundo = requestDeletion($this->pede, $outro);
    app(ApprovalService::class)->approve($segundo, $this->pede, approvalToken($this->pede));
    $outro->forceFill(['email' => 'mudou@example.com'])->save();
    $this->travel(2)->minutes();

    expect(refusal(fn () => app(ApprovalService::class)->execute($segundo, $this->pede)))->toBe(__('admin.approvals.stale'))
        ->and(User::query()->whereKey($outro->id)->exists())->toBeTrue();
});

it('trocar a config para "um operador" NÃO afrouxa um pedido feito em quatro olhos', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);

    config()->set('admin.approvals.mode', 'single_operator');

    expect(refusal(fn () => app(ApprovalService::class)->approve($pedido, $this->pede, approvalToken($this->pede))))
        ->toBe(__('admin.approvals.own_request'));
});

it('modo de um operador e confirmação desligada AVISAM no log a cada boot', function (): void {
    $this->bootWith(['admin.approvals.actions' => ['users.delete'], 'admin.approvals.mode' => 'single_operator']);

    Log::spy();
    (new AdminServiceProvider($this->app))->boot();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'ADMIN_APPROVALS_MODE=single_operator'))->atLeast()->once();

    $this->bootWith(['admin.approvals.actions' => ['users.delete'], 'admin.approvals.sensitive_confirmation' => false]);

    Log::spy();
    (new AdminServiceProvider($this->app))->boot();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'ADMIN_APPROVALS_SENSITIVE=false'))->atLeast()->once();
});

it('a tela de aprovações: pendentes no menu, o detalhe com o antes/depois e a recusa com motivo', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);
    $this->actingAs($this->aprova);

    $this->get('/admin/approval-requests')->assertOk()->assertSee(__('admin.approvals.action_users_delete'));
    $this->get("/admin/approval-requests/{$pedido->uuid}")->assertOk()->assertSee('a***@example.com')->assertDontSee('alvo-exclusao@example.com');

    Livewire::test(ViewApprovalRequest::class, ['record' => $pedido->uuid])
        ->callAction('reject', data: ['decision_reason' => 'Falta confirmação'])
        ->assertHasNoActionErrors();

    expect($pedido->fresh()->status)->toBe(ApprovalStatus::Rejected);
});

it('ação que exige aprovação SEMPRE (alwaysRequiresApproval) vale sem estar na config — e a config não a desliga', function (): void {
    config()->set('admin.approvals.actions', []);

    Approvals::register(new class extends ApprovableAction
    {
        public function key(): string
        {
            return 'users.sempre';
        }

        public function subjectModel(): string
        {
            return User::class;
        }

        public function alwaysRequiresApproval(): bool
        {
            return true;
        }

        public function execute(Model $subject, array $data, Authenticatable $actor): void {}
    });

    expect(Approvals::requires('users.sempre'))->toBeTrue()
        ->and(Approvals::requires(DeleteUserApproval::KEY))->toBeFalse();
});

it('EXCLUIR pedido: só encerrado e só com `approvals.delete`; o histórico fica na trilha', function (): void {
    $pedido = requestDeletion($this->pede, $this->alvo);

    // Em aberto: recusado.
    expect(refusal(fn () => app(ApprovalService::class)->delete($pedido, $this->aprova)))->toBe(__('admin.approvals.still_open'));

    app(ApprovalService::class)->reject($pedido, $this->aprova, 'Não confirmado');

    // Sem a permissão (operação não tem `approvals.delete`): recusado.
    $operador = User::fixture(['is_admin' => true, 'admin_role' => 'operations']);
    expect(refusal(fn () => app(ApprovalService::class)->delete($pedido, $operador)))
        ->toBe(__('admin.authorization.denied', ['permission' => 'approvals.delete']));

    // Pela tela, como dono.
    $this->actingAs($this->aprova);
    Livewire::test(ViewApprovalRequest::class, ['record' => $pedido->uuid])
        ->callAction('delete')
        ->assertHasNoActionErrors();

    expect(ApprovalRequest::query()->whereKey($pedido->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'approval_request.deleted')->where('outcome', 'success')->sole()->subject_uuid)->toBe($pedido->uuid);
});

it('a notificação do pedido feito pela tela traz o link do pedido', function (): void {
    $this->actingAs($this->pede);

    Livewire::test(ListUsers::class)
        ->callTableAction('delete', $this->alvo, data: ['approval_reason' => 'Motivo']);

    $pedido = ApprovalRequest::query()->sole();

    $notificacoes = new FilamentNotifications;
    $notificacoes->mount();
    $enviada = $notificacoes->notifications->first(fn ($n): bool => $n->getTitle() === __('admin.approvals.requested'));

    expect($enviada)->not->toBeNull()
        ->and(collect($enviada->getActions())->map(fn ($a) => $a->getUrl())->all())
        ->toContain(ApprovalRequestResource::getUrl('view', ['record' => $pedido]));
});
