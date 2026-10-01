<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals\Enums;

/**
 * Situação de um pedido de aprovação. Só `pending` (e, no modo de um
 * operador, `approved` antes de executar) ainda muda; as demais são finais.
 */
enum ApprovalStatus: string
{
    /** Aguardando a decisão. */
    case Pending = 'pending';

    /** Aprovado, aguardando a execução (só no modo de um operador). */
    case Approved = 'approved';

    /** Aprovado e executado — uma vez só. */
    case Executed = 'executed';

    /** Recusado por quem decide, com o motivo. */
    case Rejected = 'rejected';

    /** Passou da validade sem decisão (ou sem execução). */
    case Expired = 'expired';

    /** O registro alvo mudou (ou sumiu) depois do pedido: não executa. */
    case Stale = 'stale';

    /** A execução falhou; nada dela ficou gravado. */
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }

    public function label(): string
    {
        return __('admin.approvals.status_'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Approved => 'warning',
            self::Executed => 'success',
            self::Rejected, self::Failed => 'danger',
            self::Expired, self::Stale => 'gray',
        };
    }
}
