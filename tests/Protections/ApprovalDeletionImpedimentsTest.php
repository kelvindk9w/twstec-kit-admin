<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Accounts\Deletion\DeletionRequest;
use Twstec\Kit\Admin\Approvals\ApprovalService;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Approvals\Models\ApprovalRequest;
use Twstec\Kit\Admin\Resources\Users\Support\DeleteUserApproval;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// EXCLUIR USUÁRIO COM APROVAÇÃO EM DOIS PASSOS e os IMPEDIMENTOS DE EXCLUSÃO
// (twstec/kit-accounts), em cada passo (aplicação limpa):
//
// - no PEDIDO: impedimento declarado recusa o pedido já na criação, com o
//   motivo traduzido e a recusa na trilha — nenhum pedido nasce;
// - na APROVAÇÃO: impedimento que surgiu depois do pedido recusa a aprovação
//   (o pedido continua pendente), com a trilha;
// - na EXECUÇÃO (quatro olhos e um operador): registro do aplicativo que
//   aponta para a pessoa sem ter sido declarado (chave estrangeira RESTRICT)
//   → pedido `failed` com a mensagem TRADUZIDA (nunca o texto do banco), nada
//   apagado, `user.deleted` `denied` com o motivo na trilha.
// =============================================================================

beforeEach(function (): void {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);
    config()->set('admin.approvals.actions', [DeleteUserApproval::KEY]);

    $this->pede = $this->admin(['email' => 'pede-impedimento@example.com']);
    $this->aprova = $this->admin(['email' => 'aprova-impedimento@example.com']);
    $this->alvo = User::fixture(['email' => 'alvo-impedimento@example.com']);
});

function tokenParaAprovar(User $user): string
{
    $user->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();
    app(SensitiveActionService::class)->sendCode($user, 'Trans4cao!Segura');

    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::SensitiveAction)
        ->last();

    return app(SensitiveActionService::class)->confirmCode($user, $mail->code)['token'];
}

function impedirExclusaoDe(User $alvo, string $motivo): void
{
    app(DeletionImpediments::class)->register(fn (DeletionRequest $pedido): array => $pedido->person?->getKey() === $alvo->getKey()
        ? [new DeletionImpediment('retained_records', $motivo)]
        : []);
}

/**
 * Registro do aplicativo que aponta para a pessoa (RESTRICT). As chaves
 * estrangeiras do SQLite da suíte ficam desligadas dentro da transação do
 * teste: o gatilho dá o MESMO erro que a RESTRICT (a RESTRICT de verdade, no
 * SQLite e no PostgreSQL, é coberta na suíte do starter).
 */
function registroDoAppApontandoPara(User $alvo): void
{
    Schema::create('registros_do_app_aprov', fn (Blueprint $t) => $t->foreignId('user_id'));
    DB::table('registros_do_app_aprov')->insert(['user_id' => $alvo->id]);
    DB::unprepared("CREATE TRIGGER registros_aprov_restrict BEFORE DELETE ON users
        WHEN EXISTS (SELECT 1 FROM registros_do_app_aprov WHERE user_id = OLD.id)
        BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END");
}

it('PEDIDO: impedimento declarado recusa já na criação — motivo traduzido, recusa na trilha, nenhum pedido', function (): void {
    impedirExclusaoDe($this->alvo, 'Há registros desta pessoa que a lei manda guardar.');

    try {
        app(ApprovalService::class)->request(DeleteUserApproval::KEY, $this->alvo, [], 'Conta duplicada', $this->pede);
        $this->fail('O pedido deveria ter sido recusado.');
    } catch (RecordedDenial $recusa) {
        expect($recusa->getMessage())->toBe('Há registros desta pessoa que a lei manda guardar.')
            ->and($recusa->event->outcome)->toBe(AuditOutcome::Denied);
    }

    expect(ApprovalRequest::query()->count())->toBe(0)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue();
});

it('APROVAÇÃO: impedimento que surgiu DEPOIS do pedido recusa a aprovação; o pedido segue pendente e nada sai', function (): void {
    $pedido = app(ApprovalService::class)->request(DeleteUserApproval::KEY, $this->alvo, [], 'Conta duplicada', $this->pede);

    impedirExclusaoDe($this->alvo, 'Contrato vigente com esta pessoa.');

    try {
        app(ApprovalService::class)->approve($pedido, $this->aprova, tokenParaAprovar($this->aprova));
        $this->fail('A aprovação deveria ter sido recusada.');
    } catch (RecordedDenial $recusa) {
        expect($recusa->getMessage())->toBe('Contrato vigente com esta pessoa.');
    }

    expect($pedido->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue();
});

it('EXECUÇÃO (quatro olhos): registro do aplicativo com RESTRICT não declarado → pedido `failed` com a mensagem traduzida, nada apagado, recusa na trilha', function (): void {
    $pedido = app(ApprovalService::class)->request(DeleteUserApproval::KEY, $this->alvo, [], 'Conta duplicada', $this->pede);

    registroDoAppApontandoPara($this->alvo);

    $resultado = app(ApprovalService::class)->approve($pedido, $this->aprova, tokenParaAprovar($this->aprova));

    expect($resultado->status)->toBe(ApprovalStatus::Failed)
        ->and($resultado->failure_reason)->toBe(__('accounts.deletion.referenced'))
        ->and($resultado->failure_reason)->not->toContain('FOREIGN KEY')
        ->and($resultado->failure_reason)->not->toContain('Exception')
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue()
        ->and(DB::table('registros_do_app_aprov')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'approval_request.failed')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Success)->exists())->toBeFalse();

    $recusa = AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->sole();

    expect($recusa->subject_uuid)->toBe((string) $this->alvo->uuid)
        ->and($recusa->actor_uuid)->toBe((string) $this->aprova->uuid)
        ->and($recusa->reason)->toBe(__('accounts.deletion.referenced'));
});

it('EXECUÇÃO (um operador, no segundo passo): a mesma recusa limpa', function (): void {
    config()->set('admin.approvals.mode', 'single_operator');
    config()->set('admin.approvals.single_operator.min_wait_minutes', 1);

    $pedido = app(ApprovalService::class)->request(DeleteUserApproval::KEY, $this->alvo, [], 'Conta duplicada', $this->pede);
    app(ApprovalService::class)->approve($pedido, $this->pede, tokenParaAprovar($this->pede));

    registroDoAppApontandoPara($this->alvo);
    $this->travel(2)->minutes();

    $resultado = app(ApprovalService::class)->execute($pedido, $this->pede);

    expect($resultado->status)->toBe(ApprovalStatus::Failed)
        ->and($resultado->failure_reason)->toBe(__('accounts.deletion.referenced'))
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->sole()->reason)->toBe(__('accounts.deletion.referenced'));
});

it('EXECUÇÃO: impedimento DECLARADO que só aparece na hora (corrida com a aprovação) também vira `failed` limpo', function (): void {
    $pedido = app(ApprovalService::class)->request(DeleteUserApproval::KEY, $this->alvo, [], 'Conta duplicada', $this->pede);
    $token = tokenParaAprovar($this->aprova);

    // Só responde "impedido" quando o pedido já está aprovado, isto é, na
    // hora de apagar (a revalidação da aprovação passou): a corrida entre a
    // tela e a execução.
    $consultas = [];
    app(DeletionImpediments::class)->register(function (DeletionRequest $quem) use ($pedido, &$consultas): array {
        $situacao = ApprovalRequest::query()->whereKey($pedido->id)->first()?->status?->value;
        $consultas[] = $situacao;

        return $situacao === ApprovalStatus::Approved->value ? [new DeletionImpediment('retained_records', 'Registros guardados por lei.')] : [];
    });

    $resultado = app(ApprovalService::class)->approve($pedido, $this->aprova, $token);

    expect($resultado->status)->toBe(ApprovalStatus::Failed)
        ->and($resultado->failure_reason)->toBe('Registros guardados por lei.')
        ->and(User::query()->whereKey($this->alvo->id)->exists())->toBeTrue()
        ->and($consultas)->toContain(ApprovalStatus::Pending->value, ApprovalStatus::Approved->value)
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->sole()->reason)->toBe('Registros guardados por lei.');
});
