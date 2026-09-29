<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * Colunas que TODA listagem do super admin repetia palavra por palavra
 * do super admin. Cada fábrica devolve a coluna já configurada e ainda
 * encadeável — o recurso só ajusta o que for específico dele.
 *
 * Regras embutidas aqui, uma vez só:
 * - código público: rótulo comum, pesquisável e copiável (o id interno
 *   NUNCA aparece: impede enumeração de registros);
 * - data: sempre no fuso de EXIBIÇÃO da plataforma (platform()
 *   ->displayTimezone), nunca no fuso do banco.
 */
final class AdminColumns
{
    /**
     * Código público legível (PREFIXO-XXXXXX — HasPublicCode).
     */
    public static function publicCode(string $name = 'codigo_publico'): TextColumn
    {
        return TextColumn::make($name)
            ->label(__('admin.common.code'))
            ->searchable()
            ->copyable()
            ->copyMessage(__('admin.common.copied'));
    }

    /**
     * A CONTA dona do registro (código público ACC-xxxxxx) — o /admin opera
     * em modo sistema e vê todas as contas; esta coluna diz de qual é cada
     * linha.
     */
    public static function account(): TextColumn
    {
        return TextColumn::make('account.codigo_publico')
            ->label(__('admin.common.account'))
            ->searchable()
            ->copyable()
            ->copyMessage(__('admin.common.copied'));
    }

    /**
     * Dono da conta do registro (e-mail).
     */
    public static function accountOwner(string $label): TextColumn
    {
        return TextColumn::make('account.owner.email')
            ->label($label)
            ->searchable();
    }

    /**
     * Quem criou o registro (pode ter saído da conta — o registro é da conta).
     */
    public static function creator(): TextColumn
    {
        return TextColumn::make('creator.email')
            ->label(__('admin.common.created_by'))
            ->placeholder('—')
            ->toggleable(isToggledHiddenByDefault: true);
    }

    /**
     * Filtro simples por conta (código público).
     */
    public static function accountFilter(): SelectFilter
    {
        return SelectFilter::make('account')
            ->label(__('admin.common.account_filter'))
            ->relationship('account', 'codigo_publico')
            ->searchable();
    }

    /**
     * Data/hora no fuso de exibição da plataforma.
     */
    public static function dateTime(string $name = 'created_at', ?string $label = null, string $format = 'd/m/Y H:i'): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __('admin.common.created_at'))
            ->dateTime($format, platform()->displayTimezone)
            ->sortable();
    }
}
