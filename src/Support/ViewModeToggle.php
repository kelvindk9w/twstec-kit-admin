<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;

/**
 * O alternador TABELA ↔ CARDS das listagens do super admin.
 *
 * ONDE ELE FICA (e por quê mudou): antes era um botão com texto no
 * cabeçalho, colado em "Novo usuário". Duas ações de natureza diferente
 * lado a lado — uma cria registro, a outra só muda como você olha — e a
 * ação primária da tela perdia destaque. Agora ele mora na BARRA DA
 * TABELA, junto do ícone de filtros e da busca: os três controlam a
 * MESMA coisa (como a lista aparece), então ficam no mesmo lugar.
 *
 * A forma acompanha a vizinhança: SÓ ÍCONE (grade ↔ lista), sem texto,
 * com o nome no hover — igual ao botão de filtros do Filament. O ícone
 * mostra o DESTINO do clique, não o estado atual: em tabela, o ícone é a
 * grade ("ir para cards").
 *
 * Rótulo e ícone são CLOSURES: avaliados na renderização, então o botão
 * já volta descrevendo o próximo destino no mesmo clique que trocou o
 * modo.
 */
final class ViewModeToggle
{
    public const NAME = 'toggleViewMode';

    /**
     * @param  class-string<BaseResource>  $resource
     */
    public static function make(string $resource): Action
    {
        $label = fn (): string => ViewMode::for($resource)->isGrid()
            ? __('admin.common.view_as_table')
            : __('admin.common.view_as_cards');

        return Action::make(self::NAME)
            ->label($label)
            ->tooltip($label)
            ->iconButton()
            ->icon(fn (): Heroicon => ViewMode::for($resource)->isGrid()
                ? Heroicon::OutlinedTableCells
                : Heroicon::OutlinedSquares2x2)
            ->color('gray')
            ->action(function (HasTable $livewire) use ($resource): void {
                ViewMode::store($resource, ViewMode::for($resource)->toggled());

                // A Table é montada no boot do Livewire: sem remontar, a
                // tela ficaria um clique atrasada.
                $livewire->resetTable();
            });
    }
}
