<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Uploads;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Admin\Resources\Uploads\Pages\ListUploads;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\BaseResource;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * Uploads — visão global (super admin). Somente leitura: todo
 * registro aqui passou pela validação de segurança do SecureUploadService (rejeitados
 * não tocam o banco). Abrir arquivo = URL assinada de curta duração.
 *
 * O /admin opera em modo sistema (todas as contas): cada linha mostra a CONTA
 * dona do upload (com filtro), quem enviou, e o tipo — da conta, foto
 * pessoal (da pessoa, sem conta) ou órfão (da migração para contas, à espera
 * da limpeza).
 */
final class UploadResource extends BaseResource
{
    protected static ?string $model = Upload::class;

    protected static string $translationKey = 'admin.uploads';

    protected static ?string $navigationGroupKey = 'admin.nav.group_security';

    // Navegação do /admin: TODO resource tem ícone (crítica de design #6 —
    // metade da nav aparecia como bolinha sem ícone).
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function tableColumns(): array
    {
        return [
            AdminColumns::publicCode(),
            TextColumn::make('original_name')
                ->label(__('admin.uploads.original_name'))
                ->searchable()
                ->limit(40),
            self::mimeColumn(),
            self::sizeColumn(),
            AdminColumns::account()
                ->placeholder('—'),
            self::kindColumn(),
            TextColumn::make('creator.email')
                ->label(__('admin.uploads.creator'))
                ->placeholder('—')
                ->searchable(),
            AdminColumns::dateTime('created_at', __('panel.common.created_at')),
        ];
    }

    public static function cardComponents(): array
    {
        return [
            Stack::make([
                Split::make([
                    TextColumn::make('original_name')
                        ->label(__('admin.uploads.original_name'))
                        ->weight(FontWeight::SemiBold)
                        ->searchable()
                        ->limit(40),
                    self::mimeColumn()->grow(false),
                ]),
                Split::make([
                    self::sizeColumn()
                        ->size(TextSize::Small)
                        ->color('gray'),
                    AdminColumns::dateTime('created_at', __('panel.common.created_at'))
                        ->size(TextSize::Small)
                        ->color('gray')
                        ->grow(false),
                ]),
                Split::make([
                    AdminColumns::account()
                        ->icon(Heroicon::OutlinedBuildingOffice2)
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->placeholder('—'),
                    self::kindColumn()->grow(false),
                ]),
                TextColumn::make('creator.email')
                    ->label(__('admin.uploads.creator'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->color('gray')
                    ->size(TextSize::Small)
                    ->placeholder('—')
                    ->searchable(),
            ])->space(2),
        ];
    }

    /**
     * Da conta, foto pessoal ou órfão.
     */
    public static function kindOf(Upload $record): string
    {
        return match (true) {
            $record->isOrphaned() => 'orphaned',
            $record->isPersonal() => 'personal',
            default => 'account',
        };
    }

    private static function kindColumn(): TextColumn
    {
        return TextColumn::make('kind')
            ->label(__('admin.uploads.kind'))
            ->state(fn (Upload $record): string => self::kindOf($record))
            ->formatStateUsing(fn (string $state): string => __('admin.uploads.kind_'.$state))
            ->badge()
            ->color(fn (string $state): string => match ($state) {
                'orphaned' => 'danger',
                'personal' => 'info',
                default => 'gray',
            });
    }

    private static function mimeColumn(): TextColumn
    {
        return TextColumn::make('mime')
            ->label(__('admin.uploads.mime'))
            ->badge()
            ->color('gray');
    }

    private static function sizeColumn(): TextColumn
    {
        return TextColumn::make('size')
            ->label(__('admin.uploads.size'))
            ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1, ',', '.').' KB')
            ->sortable();
    }

    protected static function tableExtras(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['account', 'creator']))
            ->filters([
                AdminColumns::accountFilter(),
                SelectFilter::make('kind')
                    ->label(__('admin.uploads.kind'))
                    ->options([
                        'account' => __('admin.uploads.kind_account'),
                        'personal' => __('admin.uploads.kind_personal'),
                        'orphaned' => __('admin.uploads.kind_orphaned'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'account' => $query->whereNotNull('account_id'),
                        'personal' => $query->where('personal', true),
                        'orphaned' => $query->whereNotNull('orphaned_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.uploads.open'))
                    ->iconButton()
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Upload $record): string => $record->url())
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUploads::route('/'),
        ];
    }
}
