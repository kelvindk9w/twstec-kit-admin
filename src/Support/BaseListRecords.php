<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;
use Twstec\Kit\Admin\Support\Concerns\MigratesLegacyTableState;

/**
 * Base das páginas de LISTAGEM do super admin.
 *
 * Entrega de graça, para todo resource que estende BaseResource:
 *
 * - filtros refletidos na query string (?filters[...]=...), para que uma
 *   busca do operador possa ser compartilhada por link.
 *
 * O ALTERNADOR tabela/cards NÃO mora mais aqui. Ele saiu do cabeçalho (onde
 * dividia espaço com "Novo usuário", uma ação de natureza completamente
 * diferente) e passou a ser uma ação da BARRA DA TABELA, ao lado do ícone
 * de filtros — ver BaseResource::table() e ViewModeToggle. Efeito colateral
 * bem-vindo: nenhuma página de listagem consegue mais derrubar o alternador
 * ao sobrescrever `getHeaderActions()`, porque ele nem passa por aqui.
 *
 * Ações próprias da página (ex.: "Novo usuário") continuam em
 * `getResourceHeaderActions()`.
 */
abstract class BaseListRecords extends ListRecords
{
    use MigratesLegacyTableState;

    /** @var array<string, mixed>|null */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    /**
     * Ações específicas da página (create, importar etc.).
     *
     * @return array<Action>
     */
    protected function getResourceHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return $this->getResourceHeaderActions();
    }
}
