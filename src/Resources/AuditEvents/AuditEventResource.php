<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\AuditEvents;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Admin\Resources\AuditEvents\Pages\ListAuditEvents;
use Twstec\Kit\Admin\Resources\AuditEvents\Pages\ViewAuditEvent;
use Twstec\Kit\Admin\Resources\RequestLogs\RequestLogResource;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\BaseResource;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Identifiers\UuidColumn;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Auditoria — a trilha de AÇÕES (super admin). ESTRITAMENTE somente leitura:
 * a tabela é append-only (model, builder e gatilho do PostgreSQL) e esta tela
 * não oferece criar, editar nem excluir.
 *
 * Responde às três perguntas de auditoria, com filtro para cada uma e por
 * período: o que FULANO fez (quem agiu), o que aconteceu com ESTE registro
 * (tipo + uuid) e onde aconteceu ESTA ação (nome da ação). Recusas
 * (`denied`) aparecem em vermelho, com o motivo no detalhe.
 *
 * O detalhe mostra o resumo do que mudou exatamente como foi gravado — já
 * mascarado (AuditChanges): segredo nunca chega à tabela, e-mail e nome só
 * parcialmente. E aponta para a linha da trilha de requisições pelo
 * `correlation_id` (a requisição que executou a ação).
 */
final class AuditEventResource extends BaseResource
{
    protected static ?string $model = AuditEvent::class;

    protected static string $translationKey = 'admin.audit';

    protected static ?string $navigationGroupKey = 'admin.nav.group_security';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $defaultSortColumn = 'occurred_at';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------
    // Rótulos (compartilhados entre tabela, cards, filtros e detalhe)
    // -------------------------------------------------------------------

    public static function outcomeLabel(AuditOutcome $outcome): string
    {
        return __('admin.audit.outcome_'.$outcome->value);
    }

    public static function outcomeColor(AuditOutcome $outcome): string
    {
        return $outcome === AuditOutcome::Denied ? 'danger' : 'success';
    }

    public static function contextLabel(AuditContext $context): string
    {
        return __('admin.audit.context_'.$context->value);
    }

    /**
     * Tipo do registro traduzido; tipo sem tradução (model novo) aparece cru
     * em vez de mostrar a chave de tradução.
     */
    public static function subjectTypeLabel(?string $type): string
    {
        if ($type === null) {
            return '—';
        }

        $key = 'admin.audit.type_'.$type;

        return Lang::has($key) ? __($key) : $type;
    }

    public static function actorLabel(AuditEvent $record): string
    {
        if ($record->actor_uuid === null) {
            return __('admin.audit.actor_system');
        }

        $actor = $record->actor;

        return $actor instanceof AuthUser ? (string) $actor->email : __('admin.audit.actor_removed');
    }

    /**
     * Link para o registro afetado, quando ele ainda existe e o painel tem
     * uma tela de detalhe ou edição para o tipo dele — descoberto pelos
     * resources do painel, sem mapa fixo.
     */
    public static function subjectUrl(AuditEvent $record): ?string
    {
        if ($record->subject_type === null || ! UuidColumn::isValid($record->subject_uuid)) {
            return null;
        }

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            $model = $resource::getModel();

            if (AuditTrail::subjectType($model) !== $record->subject_type) {
                continue;
            }

            $page = collect(['view', 'edit'])->first(fn (string $page): bool => $resource::hasPage($page));

            if ($page === null) {
                return null;
            }

            $subject = $model::query()->where('uuid', $record->subject_uuid)->first();

            return $subject instanceof Model ? $resource::getUrl($page, ['record' => $subject]) : null;
        }

        return null;
    }

    /**
     * Link para a linha da trilha de REQUISIÇÕES que executou a ação.
     */
    public static function requestLogUrl(AuditEvent $record): ?string
    {
        if ($record->correlation_id === null) {
            return null;
        }

        $log = RequestLog::query()->where('correlation_id', $record->correlation_id)->first();

        return $log instanceof RequestLog ? RequestLogResource::getUrl('view', ['record' => $log]) : null;
    }

    /**
     * O resumo do que mudou como linhas de tabela (campo / antes / depois),
     * com os valores já mascarados na gravação apenas formatados para leitura.
     *
     * @return list<array{field: string, before: string, after: string}>
     */
    public static function changeRows(AuditEvent $record): array
    {
        $rows = [];

        foreach ($record->changes ?? [] as $field => $pair) {
            $rows[] = [
                'field' => (string) $field,
                'before' => self::displayValue(is_array($pair) ? ($pair['before'] ?? null) : null),
                'after' => self::displayValue(is_array($pair) ? ($pair['after'] ?? null) : $pair),
            ];
        }

        return $rows;
    }

    private static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    // -------------------------------------------------------------------
    // Colunas
    // -------------------------------------------------------------------

    private static function actionColumn(): TextColumn
    {
        return TextColumn::make('action')
            ->label(__('admin.audit.action'))
            ->fontFamily(FontFamily::Mono)
            ->searchable();
    }

    private static function outcomeColumn(): TextColumn
    {
        return TextColumn::make('outcome')
            ->label(__('admin.audit.outcome'))
            ->badge()
            ->formatStateUsing(fn (AuditOutcome $state): string => self::outcomeLabel($state))
            ->color(fn (AuditOutcome $state): string => self::outcomeColor($state));
    }

    private static function contextColumn(): TextColumn
    {
        return TextColumn::make('context')
            ->label(__('admin.audit.context'))
            ->badge()
            ->color('gray')
            ->formatStateUsing(fn (AuditContext $state): string => self::contextLabel($state));
    }

    private static function actorColumn(): TextColumn
    {
        return TextColumn::make('actor_uuid')
            ->label(__('admin.audit.actor'))
            ->getStateUsing(fn (AuditEvent $record): string => self::actorLabel($record))
            ->icon(Heroicon::OutlinedUserCircle);
    }

    private static function subjectColumn(): TextColumn
    {
        return TextColumn::make('subject_type')
            ->label(__('admin.audit.subject'))
            ->getStateUsing(fn (AuditEvent $record): string => self::subjectTypeLabel($record->subject_type))
            ->description(fn (AuditEvent $record): ?string => $record->subject_uuid !== null
                ? Str::limit($record->subject_uuid, 13, '…')
                : null);
    }

    public static function tableColumns(): array
    {
        return [
            AdminColumns::dateTime('occurred_at', __('admin.audit.occurred_at'), 'd/m/Y H:i:s'),
            self::actionColumn(),
            self::outcomeColumn(),
            self::actorColumn(),
            self::subjectColumn(),
            self::contextColumn(),
            TextColumn::make('ip')
                ->label(__('admin.audit.ip'))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * Modo cards: a ação em destaque, o resultado ao lado, e em volta quem
     * agiu, sobre qual registro e quando.
     */
    public static function cardComponents(): array
    {
        return [
            Stack::make([
                Split::make([
                    self::actionColumn()
                        ->weight(FontWeight::SemiBold),
                    self::outcomeColumn()->grow(false),
                ]),
                self::actorColumn()
                    ->color('gray')
                    ->size(TextSize::Small),
                Split::make([
                    self::subjectColumn()
                        ->size(TextSize::Small),
                    self::contextColumn()->grow(false),
                ]),
                AdminColumns::dateTime('occurred_at', __('admin.audit.occurred_at'), 'd/m/Y H:i:s')
                    ->size(TextSize::Small)
                    ->color('gray'),
            ])->space(2),
        ];
    }

    protected static function tableExtras(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('actor'))
            ->filters([
                SelectFilter::make('action')
                    ->label(__('admin.audit.filter_action'))
                    ->options(fn (): array => AuditEvent::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all())
                    ->searchable(),
                SelectFilter::make('outcome')
                    ->label(__('admin.audit.outcome'))
                    ->options(collect(AuditOutcome::cases())
                        ->mapWithKeys(fn (AuditOutcome $o): array => [$o->value => self::outcomeLabel($o)])
                        ->all()),
                SelectFilter::make('context')
                    ->label(__('admin.audit.context'))
                    ->options(collect(AuditContext::cases())
                        ->mapWithKeys(fn (AuditContext $c): array => [$c->value => self::contextLabel($c)])
                        ->all()),
                // Quem agiu: escolhe entre as contas que aparecem na trilha
                // (por e-mail) — o valor é o uuid e passa pelo UuidColumn
                // (valor adulterado na URL não derruba o PostgreSQL).
                SelectFilter::make('actor')
                    ->label(__('admin.audit.filter_actor'))
                    ->options(fn (): array => UserModel::query()
                        ->whereIn('uuid', AuditEvent::query()->whereNotNull('actor_uuid')->distinct()->select('actor_uuid'))
                        ->orderBy('email')
                        ->pluck('email', 'uuid')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q): Builder => UuidColumn::where($q, 'actor_uuid', (string) $data['value']),
                    )),
                // Só com o twstec/kit-accounts: sem ele não há contas.
                ...(Kit::has('accounts') ? [
                    // A conta em que a ação aconteceu (`tenant_uuid`): escolhe entre
                    // as contas que aparecem na trilha, pelo código público (e o
                    // nome, na conta de empresa). O valor é o uuid e passa pelo
                    // UuidColumn, como o filtro de quem agiu.
                    SelectFilter::make('account')
                        ->label(__('admin.audit.filter_account'))
                        ->options(fn (): array => Account::query()
                            ->whereIn('uuid', AuditEvent::query()->whereNotNull('tenant_uuid')->distinct()->select('tenant_uuid'))
                            ->orderBy('codigo_publico')
                            ->get(['uuid', 'codigo_publico', 'name'])
                            ->mapWithKeys(fn (Account $account): array => [
                                (string) $account->uuid => trim($account->codigo_publico.' '.($account->name ?? '')),
                            ])
                            ->all())
                        ->searchable()
                        ->query(fn (Builder $query, array $data): Builder => $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $q): Builder => UuidColumn::where($q, 'tenant_uuid', (string) $data['value']),
                        )),
                ] : []),
                Filter::make('subject')
                    ->label(__('admin.audit.filter_subject'))
                    ->schema([
                        Select::make('type')
                            ->label(__('admin.audit.filter_subject_type'))
                            ->options(fn (): array => AuditEvent::query()
                                ->whereNotNull('subject_type')
                                ->distinct()
                                ->orderBy('subject_type')
                                ->pluck('subject_type')
                                ->mapWithKeys(fn (string $type): array => [$type => self::subjectTypeLabel($type)])
                                ->all()),
                        TextInput::make('uuid')->label(__('admin.audit.filter_subject_uuid')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['type'] ?? null), fn (Builder $q): Builder => $q->where('subject_type', (string) $data['type']))
                        ->when(filled($data['uuid'] ?? null), fn (Builder $q): Builder => UuidColumn::where($q, 'subject_uuid', trim((string) $data['uuid'])))),
                Filter::make('correlation_id')
                    ->label(__('admin.audit.correlation'))
                    ->schema([
                        TextInput::make('value')->label(__('admin.audit.correlation')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q): Builder => UuidColumn::where($q, 'correlation_id', trim((string) $data['value'])),
                    )),
                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.audit.filter_from')),
                        DatePicker::make('until')->label(__('admin.audit.filter_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->whereDate('occurred_at', '>=', (string) $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->whereDate('occurred_at', '<=', (string) $data['until']))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            // Recusas destacadas: é o que pede atenção numa trilha.
            ->recordClasses(fn (AuditEvent $record): ?string => $record->outcome === AuditOutcome::Denied
                ? 'bg-red-50 dark:bg-red-950/30'
                : null);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.audit.section_event'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('action')
                            ->label(__('admin.audit.action'))
                            ->fontFamily(FontFamily::Mono)
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('outcome')
                            ->label(__('admin.audit.outcome'))
                            ->badge()
                            ->formatStateUsing(fn (AuditOutcome $state): string => self::outcomeLabel($state))
                            ->color(fn (AuditOutcome $state): string => self::outcomeColor($state)),
                        TextEntry::make('reason')
                            ->label(__('admin.audit.reason'))
                            ->visible(fn (AuditEvent $record): bool => $record->outcome === AuditOutcome::Denied)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('occurred_at')
                            ->label(__('admin.audit.occurred_at'))
                            ->dateTime('d/m/Y H:i:s', platform()->displayTimezone),
                        TextEntry::make('context')
                            ->label(__('admin.audit.context'))
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (AuditContext $state): string => self::contextLabel($state)),
                    ]),

                Section::make(__('admin.audit.section_origin'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('actor_uuid')
                            ->label(__('admin.audit.actor'))
                            ->getStateUsing(fn (AuditEvent $record): string => self::actorLabel($record))
                            ->helperText(fn (AuditEvent $record): ?string => $record->actor_uuid),
                        IconEntry::make('actor_is_admin')
                            ->label(__('admin.audit.actor_is_admin'))
                            ->boolean()
                            ->placeholder('—'),
                        TextEntry::make('correlation_id')
                            ->label(__('admin.audit.correlation'))
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('—')
                            ->copyable()
                            ->url(fn (AuditEvent $record): ?string => self::requestLogUrl($record))
                            ->helperText(fn (AuditEvent $record): string => self::requestLogUrl($record) !== null
                                ? __('admin.audit.open_request_log')
                                : __('admin.audit.request_log_missing')),
                        TextEntry::make('ip')
                            ->label(__('admin.audit.ip'))
                            ->placeholder('—'),
                        TextEntry::make('user_agent')
                            ->label(__('admin.audit.user_agent'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.audit.section_subject'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('subject_type')
                            ->label(__('admin.audit.subject_type'))
                            ->formatStateUsing(fn (?string $state): string => self::subjectTypeLabel($state))
                            ->placeholder('—'),
                        TextEntry::make('subject_uuid')
                            ->label(__('admin.audit.subject_uuid'))
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('—')
                            ->url(fn (AuditEvent $record): ?string => self::subjectUrl($record)),
                    ]),

                Section::make(__('admin.audit.section_changes'))
                    ->description(__('admin.audit.changes_hint'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('change_rows')
                            ->hiddenLabel()
                            ->getStateUsing(fn (AuditEvent $record): array => self::changeRows($record))
                            ->placeholder(__('admin.audit.no_changes'))
                            ->table([
                                TableColumn::make(__('admin.audit.field')),
                                TableColumn::make(__('admin.audit.before')),
                                TableColumn::make(__('admin.audit.after')),
                            ])
                            ->schema([
                                TextEntry::make('field')->fontFamily(FontFamily::Mono),
                                TextEntry::make('before')->fontFamily(FontFamily::Mono),
                                TextEntry::make('after')->fontFamily(FontFamily::Mono),
                            ]),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }
}
