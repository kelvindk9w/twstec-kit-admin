<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalMode;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;

/**
 * Um pedido de aprovação em dois passos — ver Approvals\ApprovalService.
 *
 * Nada aqui é preenchido por atribuição em massa: só o serviço grava, por
 * forceFill, sob trava. Toda mudança de situação passa pela captura da
 * trilha (`approval_request.created`, `.approved`, `.rejected`,
 * `.executed`, `.failed`, `.expired`, `.stale`) com o de/para; o `payload`
 * (os dados para executar) é cifrado em repouso e sai da trilha mascarado.
 *
 * @property int $id
 * @property string $uuid
 * @property string $action
 * @property ApprovalMode $mode
 * @property ApprovalStatus $status
 * @property string|null $subject_type
 * @property string|null $subject_uuid
 * @property array<string, mixed>|null $payload
 * @property array<string, array{before: mixed, after: mixed}>|null $summary
 * @property string $fingerprint
 * @property string|null $reason
 * @property string $requested_by_uuid
 * @property Carbon $expires_at
 * @property string|null $decided_by_uuid
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $executable_after
 * @property string|null $executed_by_uuid
 * @property Carbon|null $executed_at
 * @property string|null $failure_reason
 * @property Carbon $created_at
 */
final class ApprovalRequest extends Model
{
    use HasUuids, RoutesByUuid;

    protected $table = 'admin_approval_requests';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ApprovalMode::class,
            'status' => ApprovalStatus::class,
            'payload' => 'encrypted:array',
            'summary' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'executable_after' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * A situação que a tela mostra: pendente vencido aparece como vencido
     * antes mesmo de alguém tentar decidir.
     */
    public function displayStatus(): ApprovalStatus
    {
        return $this->status->isOpen() && $this->isExpired() ? ApprovalStatus::Expired : $this->status;
    }
}
