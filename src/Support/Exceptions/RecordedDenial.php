<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support\Exceptions;

use LogicException;
use RuntimeException;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

/**
 * Recusa de um serviço do painel que JÁ ESTÁ na trilha de auditoria.
 *
 * Só nasce a partir da linha `denied` gravada (recorded()): quem a recebe
 * (a tela) só precisa avisar o operador — AdminAudit::notifyRecorded(). Não
 * existe recusar sem registrar: sem a linha, não há exceção.
 */
final class RecordedDenial extends RuntimeException
{
    private function __construct(string $reason, public readonly AuditEvent $event)
    {
        parent::__construct($reason);
    }

    public static function recorded(AuditEvent $event, string $reason): self
    {
        if ($event->outcome !== AuditOutcome::Denied) {
            throw new LogicException('RecordedDenial exige uma linha `denied` da trilha.');
        }

        return new self($reason, $event);
    }
}
