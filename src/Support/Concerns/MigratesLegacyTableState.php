<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support\Concerns;

use Twstec\Kit\Admin\Support\LegacySessionState;

/**
 * Componente de TABELA do painel (listagem ou widget) cujo estado o Filament
 * guarda na sessão pelo nome da classe: o estado gravado pelo nome antigo
 * (App\Filament\…, até a 1.x) passa para o nome novo antes de a tabela
 * lê-lo — ver LegacySessionState.
 *
 * Gancho de boot de trait do Livewire (`boot<Trait>`): roda em toda
 * requisição do componente, antes do boot da tabela, e não faz nada quando
 * não há estado antigo.
 */
trait MigratesLegacyTableState
{
    public function bootMigratesLegacyTableState(): void
    {
        LegacySessionState::migrateTable(static::class);
    }
}
