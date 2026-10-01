<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalMode;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Approvals\Models\ApprovalRequest;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\AuditChanges;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Logging\Redactor;

/**
 * APROVAÇÃO EM DOIS PASSOS — a primitiva, e a barreira de servidor dela.
 *
 * 1. request(): a ação marcada não executa; vira um pedido `pending` com
 *    quem pediu, o quê (a chave da ApprovableAction e o alvo), o antes/depois
 *    redigido, os dados para executar (cifrados), o motivo, o retrato do
 *    estado do alvo e a validade (`admin.approvals.ttl_minutes`). As guardas
 *    da ação valem já no pedido.
 * 2. approve(): sob TRAVA do pedido (SELECT ... FOR UPDATE, numa transação),
 *    confere, nesta ordem: ainda pendente → não venceu → quem aprova tem
 *    `approvals.approve` e a permissão da ação → no modo quatro olhos, NÃO
 *    é quem pediu → a ação sensível (token de uso único, consumido só se o
 *    resto passou) → o alvo existe e o estado é o do pedido → as guardas da
 *    ação, agora com quem aprova. Passou: no modo quatro olhos, executa na
 *    hora; no de um operador, marca `approved` e só deixa executar depois da
 *    espera mínima (execute()).
 * 3. execute() (modo de um operador): a mesma trava e as mesmas
 *    reconferências, mais a espera mínima.
 *
 * UMA EXECUÇÃO SÓ: a situação muda dentro da transação que segura a trava.
 * Duas aprovações ao mesmo tempo: a segunda espera a primeira terminar e
 * encontra o pedido já decidido — recusada. A execução roda num ponto de
 * salvamento: se falha, nada dela fica e o pedido vira `failed` (com a
 * mensagem redigida).
 *
 * TRILHA: cada mudança de situação é uma linha (`approval_request.created`,
 * `.approved`, `.executed`, `.failed`, `.rejected`, `.expired`, `.stale`) e
 * a execução gera as linhas do próprio alvo (`user.deleted`...). Toda recusa
 * vira `denied` com o motivo ANTES de a tela saber dela (RecordedDenial) —
 * o serviço é a barreira também fora do painel.
 */
final class ApprovalService
{
    public const APPROVE_PERMISSION = 'approvals.approve';

    public const REJECT_PERMISSION = 'approvals.reject';

    public const EXECUTE_PERMISSION = 'approvals.execute';

    public const DELETE_PERMISSION = 'approvals.delete';

    public function __construct(
        private readonly ApprovalRegistry $registry,
        private readonly SensitiveActionService $sensitiveActions,
        private readonly AuditTrail $trail,
        private readonly AuditChanges $changes,
        private readonly Redactor $redactor,
    ) {}

    /**
     * Esta ação exige aprovação agora? (registrada E — listada na config ou
     * declarada como sempre exigindo, ApprovableAction::alwaysRequiresApproval)
     */
    public function requires(string $key): bool
    {
        $action = $this->registry->get($key);

        return $action !== null
            && ($action->alwaysRequiresApproval() || in_array($key, (array) config('admin.approvals.actions', []), true));
    }

    /**
     * Aprovar no modo quatro olhos pede a ação sensível? (No de um operador,
     * sempre.)
     */
    public static function sensitiveRequired(ApprovalMode $mode): bool
    {
        return $mode === ApprovalMode::SingleOperator
            || config('admin.approvals.sensitive_confirmation', true) !== false;
    }

    /**
     * O modo que vale para este pedido (o mais forte entre o do pedido e o
     * de agora).
     */
    public static function modeFor(ApprovalRequest $request): ApprovalMode
    {
        return ApprovalMode::strictest($request->mode, ApprovalMode::configured());
    }

    /**
     * Passo 1: cria o pedido pendente.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RecordedDenial
     */
    public function request(string $key, Model $subject, array $data, ?string $reason, Authenticatable $actor): ApprovalRequest
    {
        return AdminAudit::within('requested', function () use ($key, $subject, $data, $reason, $actor): ApprovalRequest {
            $action = $this->registry->get($key);
            $reason = trim((string) $reason);
            $uuid = $subject->getAttribute('uuid');

            // O retrato e as guardas olham o registro como está NO BANCO (não
            // a cópia da tela, que pode trazer colunas calculadas).
            $fresh = $action !== null && is_string($uuid) ? $action->resolveSubject($uuid) : null;

            $denial = match (true) {
                $action === null => __('admin.approvals.unknown_action'),
                ! AdminPermissions::allows($actor, $action->permission()) => __('admin.authorization.denied', ['permission' => $action->permission()]),
                $reason === '' => __('admin.approvals.reason_required'),
                $fresh === null => __('admin.approvals.subject_missing'),
                default => $action->denial($fresh, $data, $actor),
            };

            if ($denial !== null) {
                $this->deny('approval_request.created', $subject, $denial);
            }

            /** @var ApprovableAction $action */
            /** @var Model $fresh */
            $subject = $fresh;
            $mode = ApprovalMode::configured();

            return DB::transaction(function () use ($action, $subject, $data, $reason, $actor, $mode): ApprovalRequest {
                $request = new ApprovalRequest;

                $request->forceFill([
                    'action' => $action->key(),
                    'mode' => $mode,
                    'status' => ApprovalStatus::Pending,
                    'subject_type' => AuditTrail::subjectType($subject),
                    'subject_uuid' => $subject->getAttribute('uuid'),
                    'payload' => $data,
                    'summary' => $this->changes->sanitize($action->changes($subject, $data), $subject),
                    'fingerprint' => $this->fingerprint($action, $subject, $data),
                    'reason' => Str::limit($this->redactor->redactString($reason), 1000, ''),
                    'requested_by_uuid' => self::uuidOf($actor),
                    'expires_at' => now()->addMinutes(max(1, (int) config('admin.approvals.ttl_minutes', 1440))),
                ])->save();

                return $request;
            });
        }, $actor);
    }

    /**
     * Passo 2: aprova (e, no modo quatro olhos, executa).
     *
     * @throws RecordedDenial
     */
    public function approve(ApprovalRequest|string $request, Authenticatable $actor, #[SensitiveParameter] ?string $sensitiveToken = null): ApprovalRequest
    {
        return AdminAudit::within('approved', function () use ($request, $actor, $sensitiveToken): ApprovalRequest {
            [$denial, $locked] = DB::transaction(function () use ($request, $actor, $sensitiveToken): array {
                $locked = $this->lock($request);

                if ($locked === null) {
                    return [__('admin.approvals.not_found'), null];
                }

                if ($locked->status !== ApprovalStatus::Pending) {
                    return [__('admin.approvals.already_decided'), $locked];
                }

                if ($locked->isExpired()) {
                    $this->transition($locked, ApprovalStatus::Expired, 'expired');

                    return [__('admin.approvals.expired'), $locked];
                }

                $action = $this->registry->get($locked->action);

                if ($action === null) {
                    return [__('admin.approvals.unknown_action'), $locked];
                }

                if ($missing = $this->missingPermission($actor, [self::APPROVE_PERMISSION, $action->permission()])) {
                    return [__('admin.authorization.denied', ['permission' => $missing]), $locked];
                }

                $mode = self::modeFor($locked);

                // QUATRO OLHOS: quem pediu nunca aprova o próprio pedido.
                if ($mode === ApprovalMode::FourEyes && self::uuidOf($actor) === $locked->requested_by_uuid) {
                    return [__('admin.approvals.own_request'), $locked];
                }

                [$denial, $subject] = $this->revalidate($locked, $action, $actor);

                if ($denial !== null) {
                    return [$denial, $locked];
                }

                // Ação sensível por último: o token só é consumido quando
                // todo o resto passou.
                if (self::sensitiveRequired($mode) && ! $this->sensitiveConfirmed($actor, $sensitiveToken)) {
                    return [__('admin.sensitive.required'), $locked];
                }

                $decided = [
                    'decided_by_uuid' => self::uuidOf($actor),
                    'decided_at' => now(),
                ];

                if ($mode === ApprovalMode::SingleOperator) {
                    $after = now()->addMinutes(max(0, (int) config('admin.approvals.single_operator.min_wait_minutes', 15)));

                    $this->transition($locked, ApprovalStatus::Approved, 'approved', [
                        ...$decided,
                        'executable_after' => $after,
                        'expires_at' => $after->copy()->addMinutes(max(1, (int) config('admin.approvals.single_operator.execution_window_minutes', 1440))),
                    ]);

                    return [null, $locked];
                }

                $this->transition($locked, ApprovalStatus::Approved, 'approved', $decided);
                $this->run($locked, $action, $subject, $actor);

                return [null, $locked];
            });

            if ($denial !== null) {
                $this->deny('approval_request.approved', $locked, $denial);
            }

            return $locked;
        }, $actor);
    }

    /**
     * Passo 3 do modo de um operador: executa o pedido aprovado, depois da
     * espera mínima.
     *
     * @throws RecordedDenial
     */
    public function execute(ApprovalRequest|string $request, Authenticatable $actor): ApprovalRequest
    {
        return AdminAudit::within('executed', function () use ($request, $actor): ApprovalRequest {
            [$denial, $locked] = DB::transaction(function () use ($request, $actor): array {
                $locked = $this->lock($request);

                if ($locked === null) {
                    return [__('admin.approvals.not_found'), null];
                }

                if ($locked->status !== ApprovalStatus::Approved) {
                    return [__('admin.approvals.not_approved'), $locked];
                }

                if ($locked->executable_after === null || now()->lt($locked->executable_after)) {
                    return [__('admin.approvals.wait', ['time' => $locked->executable_after?->toIso8601String() ?? '-']), $locked];
                }

                if ($locked->isExpired()) {
                    $this->transition($locked, ApprovalStatus::Expired, 'expired');

                    return [__('admin.approvals.expired'), $locked];
                }

                $action = $this->registry->get($locked->action);

                if ($action === null) {
                    return [__('admin.approvals.unknown_action'), $locked];
                }

                if ($missing = $this->missingPermission($actor, [self::EXECUTE_PERMISSION, $action->permission()])) {
                    return [__('admin.authorization.denied', ['permission' => $missing]), $locked];
                }

                [$denial, $subject] = $this->revalidate($locked, $action, $actor);

                if ($denial !== null) {
                    return [$denial, $locked];
                }

                $this->run($locked, $action, $subject, $actor);

                return [null, $locked];
            });

            if ($denial !== null) {
                $this->deny('approval_request.executed', $locked, $denial);
            }

            return $locked;
        }, $actor);
    }

    /**
     * Recusa o pedido (pendente, ou aprovado ainda sem execução), com motivo.
     *
     * @throws RecordedDenial
     */
    public function reject(ApprovalRequest|string $request, Authenticatable $actor, ?string $reason): ApprovalRequest
    {
        return AdminAudit::within('rejected', function () use ($request, $actor, $reason): ApprovalRequest {
            $reason = trim((string) $reason);

            [$denial, $locked] = DB::transaction(function () use ($request, $actor, $reason): array {
                $locked = $this->lock($request);

                if ($locked === null) {
                    return [__('admin.approvals.not_found'), null];
                }

                if (! $locked->status->isOpen()) {
                    return [__('admin.approvals.already_decided'), $locked];
                }

                if ($missing = $this->missingPermission($actor, [self::REJECT_PERMISSION])) {
                    return [__('admin.authorization.denied', ['permission' => $missing]), $locked];
                }

                if ($reason === '') {
                    return [__('admin.approvals.reason_required'), $locked];
                }

                $this->transition($locked, ApprovalStatus::Rejected, 'rejected', [
                    'decided_by_uuid' => self::uuidOf($actor),
                    'decided_at' => now(),
                    'decision_reason' => Str::limit($this->redactor->redactString($reason), 1000, ''),
                ]);

                return [null, $locked];
            });

            if ($denial !== null) {
                $this->deny('approval_request.rejected', $locked, $denial);
            }

            return $locked;
        }, $actor);
    }

    /**
     * Exclui um pedido ENCERRADO (executado, recusado, vencido, obsoleto ou
     * com falha) — limpeza da tela. Pedido em aberto não sai: recusa-se
     * antes. O histórico continua na trilha de auditoria (só-acréscimo), onde
     * a exclusão também fica (`approval_request.deleted`, com o retrato).
     *
     * @throws RecordedDenial
     */
    public function delete(ApprovalRequest|string $request, Authenticatable $actor): void
    {
        AdminAudit::within('deleted', function () use ($request, $actor): void {
            [$denial, $locked] = DB::transaction(function () use ($request, $actor): array {
                $locked = $this->lock($request);

                if ($locked === null) {
                    return [__('admin.approvals.not_found'), null];
                }

                if ($missing = $this->missingPermission($actor, [self::DELETE_PERMISSION])) {
                    return [__('admin.authorization.denied', ['permission' => $missing]), $locked];
                }

                if ($locked->status->isOpen()) {
                    return [__('admin.approvals.still_open'), $locked];
                }

                $locked->delete();

                return [null, $locked];
            });

            if ($denial !== null) {
                $this->deny('approval_request.deleted', $locked, $denial);
            }
        }, $actor);
    }

    /**
     * O retrato do estado (SHA-256 do que a ação declarou).
     *
     * @param  array<string, mixed>  $data
     */
    public function fingerprint(ApprovableAction $action, Model $subject, array $data): string
    {
        return hash('sha256', (string) json_encode(self::normalize($action->fingerprint($subject, $data)), JSON_THROW_ON_ERROR));
    }

    /**
     * Executa num ponto de salvamento: falhou, nada da execução fica e o
     * pedido vira `failed`; deu certo, `executed` — na mesma transação que
     * segura a trava. Recusa de regra (ExecutionRefused) também vira
     * `failed`, mas com a mensagem traduzida e a recusa na trilha do alvo.
     */
    private function run(ApprovalRequest $request, ApprovableAction $action, Model $subject, Authenticatable $actor): void
    {
        try {
            DB::transaction(function () use ($request, $action, $subject, $actor): void {
                $this->trail->describeAs($action->verb());

                $action->execute($subject, (array) ($request->payload ?? []), $actor);
            });
        } catch (ExecutionRefused $refused) {
            // Recusa de regra (não defeito): o motivo traduzido no pedido e
            // na trilha do alvo; nada é reportado como erro.
            $motivo = Str::limit($this->redactor->redactString($refused->getMessage()), 500, '');

            $this->transition($request, ApprovalStatus::Failed, 'failed', ['failure_reason' => $motivo]);

            $this->trail->denied(AuditTrail::subjectType($subject).'.'.$action->verb(), $subject, $motivo);

            return;
        } catch (Throwable $exception) {
            $this->transition($request, ApprovalStatus::Failed, 'failed', [
                'failure_reason' => Str::limit($exception::class.': '.$this->redactor->redactString($exception->getMessage()), 500, ''),
            ]);

            report($exception);

            return;
        }

        $this->transition($request, ApprovalStatus::Executed, 'executed', [
            'executed_by_uuid' => self::uuidOf($actor),
            'executed_at' => now(),
        ]);
    }

    /**
     * O alvo ainda existe, está como no pedido e as guardas deixam?
     * Estado diferente = o pedido fica obsoleto (`stale`) e não executa mais.
     *
     * @return array{0: string|null, 1: Model|null}
     */
    private function revalidate(ApprovalRequest $request, ApprovableAction $action, Authenticatable $actor): array
    {
        $subject = $request->subject_uuid === null ? null : $action->resolveSubject($request->subject_uuid);
        $data = (array) ($request->payload ?? []);

        if ($subject === null || ! hash_equals($request->fingerprint, $this->fingerprint($action, $subject, $data))) {
            $this->transition($request, ApprovalStatus::Stale, 'stale');

            return [__('admin.approvals.stale'), null];
        }

        $denial = $action->denial($subject, $data, $actor);

        return [$denial, $denial === null ? $subject : null];
    }

    /**
     * Muda a situação com o verbo certo na trilha (`approval_request.<verbo>`)
     * e devolve o verbo de antes ao escopo.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(ApprovalRequest $request, ApprovalStatus $status, string $verb, array $attributes = []): void
    {
        $previous = $this->trail->current()?->verb;

        $this->trail->describeAs($verb);

        try {
            $request->forceFill(['status' => $status, ...$attributes])->save();
        } finally {
            if ($previous !== null) {
                $this->trail->describeAs($previous);
            }
        }
    }

    /**
     * O pedido, travado para esta transação.
     */
    private function lock(ApprovalRequest|string $request): ?ApprovalRequest
    {
        $uuid = $request instanceof ApprovalRequest ? $request->uuid : $request;

        return ApprovalRequest::query()->where('uuid', $uuid)->lockForUpdate()->first();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function missingPermission(Authenticatable $actor, array $permissions): ?string
    {
        foreach ($permissions as $permission) {
            if (! AdminPermissions::allows($actor, $permission)) {
                return $permission;
            }
        }

        return null;
    }

    private function sensitiveConfirmed(Authenticatable $actor, #[SensitiveParameter] ?string $token): bool
    {
        return $token !== null
            && $actor instanceof AuthUser
            && $this->sensitiveActions->validateToken($actor, $token);
    }

    /**
     * Grava a recusa na trilha e a entrega à tela já registrada.
     *
     * @throws RecordedDenial
     */
    private function deny(string $action, ?Model $subject, string $reason): never
    {
        $event = $subject === null
            ? $this->trail->denied($action, null, $reason, 'approval_request')
            : $this->trail->denied($action, $subject, $reason);

        throw RecordedDenial::recorded($event, $reason);
    }

    private static function uuidOf(Authenticatable $actor): string
    {
        return $actor instanceof Model ? (string) $actor->getAttribute('uuid') : '';
    }

    /**
     * Valores estáveis para o retrato (datas e enums como texto, chaves em
     * ordem).
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return array_map(self::normalize(...), $value);
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => $value,
        };
    }
}
