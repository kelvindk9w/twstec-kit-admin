<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Accounts;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Admin\Resources\Accounts\Pages\ListAccounts;
use Twstec\Kit\Admin\Resources\Accounts\Pages\ViewAccount;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\BaseResource;

/**
 * Contas — visão global (super admin), SOMENTE LEITURA.
 *
 * Lista todas as contas (o /admin opera em modo sistema), com o tipo
 * (pessoal ou empresa), o dono e quantos membros, projetos e chaves cada uma
 * tem; o detalhe mostra os membros com o papel e os projetos e chaves da
 * conta (só identificadores públicos — nunca a secreta).
 *
 * Nada de criar, editar ou excluir por aqui: membros, convites, transferência
 * e exclusão são da própria conta, pelo painel (com a regra do dono, a
 * confirmação sensível e a trilha de cada evento). Como toda tela do painel,
 * esta roda com o escopo de auditoria aberto — uma escrita que um dia
 * aparecer aqui já nasce registrada.
 */
final class AccountResource extends BaseResource
{
    protected static ?string $model = Account::class;

    protected static string $translationKey = 'admin.accounts';

    protected static ?string $navigationGroupKey = 'admin.nav.group_management';

    // Antes de Projetos (30) e Usuários (40), depois de Chaves de API (10).
    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function tableColumns(): array
    {
        return [
            AdminColumns::publicCode(),
            self::nameColumn(),
            self::typeColumn(),
            TextColumn::make('owner.email')
                ->label(__('admin.accounts.owner'))
                ->searchable(),
            self::countColumn('memberships', __('admin.accounts.members')),
            self::countColumn('projects', __('admin.accounts.projects')),
            self::countColumn('apiKeys', __('admin.accounts.api_keys')),
            AdminColumns::dateTime('created_at', __('panel.common.created_at')),
        ];
    }

    public static function cardComponents(): array
    {
        return [
            Stack::make([
                Split::make([
                    self::nameColumn()->weight(FontWeight::SemiBold)->size(TextSize::Large),
                    self::typeColumn()->grow(false),
                ]),
                TextColumn::make('owner.email')
                    ->label(__('admin.accounts.owner'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->color('gray')
                    ->size(TextSize::Small),
                Split::make([
                    self::countColumn('memberships', __('admin.accounts.members')),
                    AdminColumns::publicCode()->size(TextSize::Small)->color('gray')->grow(false),
                ]),
            ])->space(2),
        ];
    }

    protected static function tableExtras(Table $table): Table
    {
        return $table
            ->filters([
                TernaryFilter::make('personal')
                    ->label(__('admin.accounts.type'))
                    ->trueLabel(__('admin.accounts.type_personal'))
                    ->falseLabel(__('admin.accounts.type_company'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('personal_user_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('personal_user_id'),
                    ),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.accounts.section_account'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('display_name')
                        ->label(__('panel.common.name'))
                        ->getStateUsing(fn (Account $record): string => $record->displayName())
                        ->weight(FontWeight::SemiBold),
                    TextEntry::make('codigo_publico')
                        ->label(__('admin.common.code'))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable(),
                    TextEntry::make('type')
                        ->label(__('admin.accounts.type'))
                        ->badge()
                        ->getStateUsing(fn (Account $record): string => self::typeLabel($record))
                        ->color(fn (Account $record): string => $record->isPersonal() ? 'gray' : 'info'),
                    TextEntry::make('uuid')
                        ->label(__('admin.accounts.uuid'))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable(),
                    TextEntry::make('created_at')
                        ->label(__('panel.common.created_at'))
                        ->dateTime('d/m/Y H:i', platform()->displayTimezone),
                ]),

            Section::make(__('admin.accounts.section_members'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('member_rows')
                        ->hiddenLabel()
                        ->getStateUsing(fn (Account $record): array => self::memberRows($record))
                        ->table([
                            TableColumn::make(__('panel.common.name')),
                            TableColumn::make(__('admin.accounts.email')),
                            TableColumn::make(__('admin.accounts.role')),
                            TableColumn::make(__('admin.accounts.since')),
                        ])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('email'),
                            TextEntry::make('role')->badge()->color('gray'),
                            TextEntry::make('since'),
                        ]),
                ]),

            Section::make(__('admin.accounts.section_projects'))
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('project_rows')
                        ->hiddenLabel()
                        ->getStateUsing(fn (Account $record): array => self::projectRows($record))
                        ->placeholder(__('admin.accounts.no_projects'))
                        ->table([
                            TableColumn::make(__('panel.common.name')),
                            TableColumn::make(__('admin.common.code')),
                            TableColumn::make(__('panel.common.status')),
                        ])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('code')->fontFamily(FontFamily::Mono),
                            TextEntry::make('status'),
                        ]),
                ]),

            Section::make(__('admin.accounts.section_api_keys'))
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('key_rows')
                        ->hiddenLabel()
                        ->getStateUsing(fn (Account $record): array => self::keyRows($record))
                        ->placeholder(__('admin.accounts.no_api_keys'))
                        ->table([
                            TableColumn::make(__('panel.common.name')),
                            TableColumn::make(__('admin.common.code')),
                            TableColumn::make(__('admin.accounts.public_key')),
                            TableColumn::make(__('panel.common.status')),
                            TableColumn::make(__('admin.common.created_by')),
                        ])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('code')->fontFamily(FontFamily::Mono),
                            TextEntry::make('public_key')->fontFamily(FontFamily::Mono),
                            TextEntry::make('status'),
                            TextEntry::make('creator'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'view' => ViewAccount::route('/{record}'),
        ];
    }

    public static function typeLabel(Account $account): string
    {
        return $account->isPersonal() ? __('admin.accounts.type_personal') : __('admin.accounts.type_company');
    }

    /**
     * @return list<array{name: string, email: string, role: string, since: string}>
     */
    public static function memberRows(Account $account): array
    {
        $ordem = [AccountRole::Owner->value => 0, AccountRole::Admin->value => 1, AccountRole::Member->value => 2];

        return $account->memberships()->with('user')->get()
            ->sortBy(fn (AccountMembership $m): int => $ordem[$m->role->value])
            ->map(fn (AccountMembership $m): array => [
                'name' => (string) $m->user?->getAttribute('name'),
                'email' => (string) $m->user?->getAttribute('email'),
                'role' => $m->role->label(),
                'since' => $m->created_at?->setTimezone(platform()->displayTimezone)->format('d/m/Y H:i') ?? '—',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, code: string, status: string}>
     */
    public static function projectRows(Account $account): array
    {
        return $account->projects()->orderBy('name')->get()
            ->map(fn (Project $p): array => [
                'name' => (string) $p->name,
                'code' => (string) $p->codigo_publico,
                'status' => __('admin.projects.status_'.$p->status->value),
            ])
            ->all();
    }

    /**
     * Só identificadores públicos da chave — nunca a secreta nem o hash.
     *
     * @return list<array{name: string, code: string, public_key: string, status: string, creator: string}>
     */
    public static function keyRows(Account $account): array
    {
        return $account->apiKeys()->with('creator')->latest()->get()
            ->map(fn (ApiKey $k): array => [
                'name' => (string) $k->name,
                'code' => (string) $k->codigo_publico,
                'public_key' => (string) $k->public_key,
                'status' => __('admin.api_keys.status_'.$k->status->value),
                'creator' => (string) ($k->creator?->getAttribute('email') ?? '—'),
            ])
            ->all();
    }

    private static function nameColumn(): TextColumn
    {
        return TextColumn::make('name')
            ->label(__('panel.common.name'))
            ->getStateUsing(fn (Account $record): string => $record->displayName())
            ->searchable(['name']);
    }

    private static function typeColumn(): TextColumn
    {
        return TextColumn::make('personal_user_id')
            ->label(__('admin.accounts.type'))
            ->badge()
            ->getStateUsing(fn (Account $record): string => self::typeLabel($record))
            ->color(fn (Account $record): string => $record->isPersonal() ? 'gray' : 'info');
    }

    private static function countColumn(string $relation, string $label): TextColumn
    {
        return TextColumn::make($relation.'_count')
            ->label($label)
            ->counts($relation)
            ->badge()
            ->color('gray')
            ->sortable();
    }
}
