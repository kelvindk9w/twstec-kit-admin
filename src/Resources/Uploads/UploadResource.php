<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Uploads;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Component;
use Twstec\Kit\Admin\Resources\Uploads\Pages\ListUploads;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\BaseResource;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Retention\LegalHold;

/**
 * Uploads — visão global (super admin). Somente leitura: todo
 * registro aqui passou pela validação de segurança do SecureUploadService (rejeitados
 * não tocam o banco). Abrir arquivo = URL assinada de curta duração.
 *
 * O /admin opera em modo sistema (todas as contas): cada linha mostra a CONTA
 * dona do upload (com filtro), quem enviou, e o tipo — da conta, foto
 * pessoal (da pessoa, sem conta), órfão (da migração para contas, à espera
 * da limpeza) ou retido (o dono foi excluído e o arquivo ficou só pela
 * guarda legal).
 *
 * CONFIDENCIAL: a classificação aparece em cada linha (com filtro). Abrir um
 * confidencial é outra ação, com permissão própria (`uploads.view_confidential`
 * — fora de `*.view`: o auditor vê a lista, não o documento): gera a URL da
 * rota que decifra, na hora do clique (nunca ao desenhar a tabela), e a
 * geração e a visualização vão para a trilha com o contexto `admin`.
 *
 * GUARDA LEGAL ("guardar até", permissão `uploads.legal_hold`): pôr e tirar,
 * com o motivo — registrados na trilha (Retention\LegalHold).
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
            self::classificationColumn(),
            self::retainUntilColumn(),
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
                Split::make([
                    self::classificationColumn(),
                    self::retainUntilColumn()
                        ->size(TextSize::Small)
                        ->color('gray')
                        ->grow(false),
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
     * Da conta, foto pessoal, órfão ou retido (desvinculado pela guarda legal).
     */
    public static function kindOf(Upload $record): string
    {
        return match (true) {
            $record->isDetached() => 'retained',
            $record->isOrphaned() => 'orphaned',
            $record->isPersonal() => 'personal',
            default => 'account',
        };
    }

    private static function classificationColumn(): TextColumn
    {
        return TextColumn::make('classification')
            ->label(__('admin.uploads.classification'))
            ->formatStateUsing(fn (UploadClassification $state): string => __('admin.uploads.classification_'.$state->value))
            ->badge()
            ->icon(fn (UploadClassification $state): ?Heroicon => $state->isConfidential() ? Heroicon::OutlinedLockClosed : null)
            ->color(fn (UploadClassification $state): string => match ($state) {
                UploadClassification::Confidential => 'warning',
                UploadClassification::Public => 'info',
                default => 'gray',
            });
    }

    private static function retainUntilColumn(): TextColumn
    {
        return TextColumn::make('retain_until')
            ->label(__('admin.uploads.retain_until'))
            ->date('d/m/Y', platform()->displayTimezone)
            ->placeholder('—')
            ->tooltip(fn (Upload $record): ?string => $record->retention_reason)
            ->sortable();
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
                'retained' => 'warning',
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
                        'retained' => __('admin.uploads.kind_retained'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'account' => $query->whereNotNull('account_id'),
                        'personal' => $query->where('personal', true),
                        'orphaned' => $query->whereNotNull('orphaned_at'),
                        'retained' => $query->whereNotNull('detached_at'),
                        default => $query,
                    }),
                SelectFilter::make('classification')
                    ->label(__('admin.uploads.classification'))
                    ->options(collect(UploadClassification::cases())->mapWithKeys(fn (UploadClassification $case): array => [$case->value => __('admin.uploads.classification_'.$case->value)])->all()),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.uploads.open'))
                    ->iconButton()
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn (Upload $record): bool => ! $record->isConfidential())
                    // O confidencial nunca assina daqui: a URL dele só nasce
                    // no clique (openConfidential), com a trilha.
                    ->url(fn (Upload $record): ?string => $record->isConfidential() ? null : $record->url())
                    ->openUrlInNewTab(),
                Action::make('openConfidential')
                    ->label(__('admin.uploads.open_confidential'))
                    ->iconButton()
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->visible(fn (Upload $record): bool => $record->isConfidential())
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.uploads.open_confidential'))
                    ->modalDescription(__('admin.uploads.open_confidential_warning'))
                    ->action(function (Upload $record, Component $livewire): void {
                        // Gerada AGORA, no escopo de auditoria desta chamada
                        // (contexto `admin`, quem está logado): a trilha
                        // registra a geração e, na entrega, a visualização.
                        $livewire->redirect($record->url());
                    }),
                Action::make('legalHold')
                    ->label(__('admin.uploads.legal_hold'))
                    ->iconButton()
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (Upload $record): bool => ! $record->isDetached())
                    ->fillForm(fn (Upload $record): array => [
                        'retain_until' => $record->retain_until?->toDateString(),
                        'reason' => $record->retention_reason,
                    ])
                    ->schema([
                        DatePicker::make('retain_until')
                            ->label(__('admin.uploads.legal_hold_until'))
                            ->required()
                            ->minDate(now()->addDay()->startOfDay()),
                        TextInput::make('reason')
                            ->label(__('admin.uploads.legal_hold_reason'))
                            ->helperText(__('admin.uploads.legal_hold_reason_hint'))
                            ->required()
                            ->maxLength(160),
                    ])
                    ->action(function (Upload $record, array $data, Action $action): void {
                        try {
                            app(LegalHold::class)->place($record, Carbon::parse((string) $data['retain_until'])->endOfDay(), (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            AdminAudit::denied($exception->getMessage(), $record, 'legal_hold_placed');

                            $action->halt();
                        }
                    })
                    ->successNotificationTitle(__('admin.uploads.legal_hold_placed')),
                Action::make('releaseLegalHold')
                    ->label(__('admin.uploads.release_legal_hold'))
                    ->iconButton()
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->color('danger')
                    ->visible(fn (Upload $record): bool => $record->isUnderLegalHold())
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.uploads.release_legal_hold_warning'))
                    ->schema([
                        TextInput::make('reason')
                            ->label(__('admin.uploads.release_reason'))
                            ->required()
                            ->maxLength(160),
                    ])
                    ->action(function (Upload $record, array $data, Action $action): void {
                        try {
                            app(LegalHold::class)->release($record, (string) $data['reason']);
                        } catch (InvalidArgumentException $exception) {
                            AdminAudit::denied($exception->getMessage(), $record, 'legal_hold_released');

                            $action->halt();
                        }
                    })
                    ->successNotificationTitle(__('admin.uploads.legal_hold_released')),
            ]);
    }

    /**
     * "Abrir" só leva ao arquivo (URL assinada): pede o mesmo que ver a lista.
     * Abrir um CONFIDENCIAL pede permissão própria (fora de `*.view`), e a
     * guarda legal também.
     *
     * @return array<string, string|null>
     */
    public static function actionAbilities(): array
    {
        return [
            'open' => 'view',
            'openConfidential' => 'view_confidential',
            'legalHold' => 'legal_hold',
            'releaseLegalHold' => 'legal_hold',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUploads::route('/'),
        ];
    }
}
