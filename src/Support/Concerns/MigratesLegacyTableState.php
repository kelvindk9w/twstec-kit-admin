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
 * A chave NOVA é a que o próprio Filament instalado calcula: cada getter de
 * chave de sessão da tabela (`getTablePerPageSessionKey()` e os demais) passa
 * por aqui e, na primeira vez, traz o valor da chave antiga. O formato muda
 * entre versões do Filament (`tables.<md5>_<estado>` até a 5.10,
 * `filament.tables.<sha256>.<estado>` a partir da 5.11) — perguntar a ele, em
 * vez de remontar a chave, é o que mantém a migração certa nas duas. Os
 * getters só são chamados depois de a tabela existir (no `booted` do
 * Filament, antes de ler a sessão), e não fazem nada quando não há estado
 * antigo.
 */
trait MigratesLegacyTableState
{
    public function getTablePerPageSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('per_page', parent::getTablePerPageSessionKey());
    }

    public function getTableSortSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('sort', parent::getTableSortSessionKey());
    }

    public function getTableFiltersSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('filters', parent::getTableFiltersSessionKey());
    }

    public function getTableSearchSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('search', parent::getTableSearchSessionKey());
    }

    public function getTableColumnSearchesSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('column_search', parent::getTableColumnSearchesSessionKey());
    }

    public function getTableColumnsSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('columns', parent::getTableColumnsSessionKey());
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('has_reordered_columns', parent::getHasReorderedTableColumnsSessionKey());
    }

    public function getTableGroupingSessionKey(): string
    {
        return $this->migrateLegacyTableSessionKey('grouping', parent::getTableGroupingSessionKey());
    }

    private function migrateLegacyTableSessionKey(string $suffix, string $key): string
    {
        LegacySessionState::migrateTableKey(static::class, $suffix, $key);

        return $key;
    }
}
