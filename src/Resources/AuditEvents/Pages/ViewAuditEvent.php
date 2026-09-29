<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\AuditEvents\Pages;

use Filament\Resources\Pages\ViewRecord;
use Twstec\Kit\Admin\Resources\AuditEvents\AuditEventResource;

/**
 * Detalhe de um evento: quem, de onde, em qual registro e o resumo do que
 * mudou — já mascarado na gravação (AuditChanges), nunca decifrado aqui.
 */
final class ViewAuditEvent extends ViewRecord
{
    protected static string $resource = AuditEventResource::class;
}
