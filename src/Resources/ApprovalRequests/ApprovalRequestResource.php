<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\ApprovalRequests;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Twstec\Kit\Admin\Approvals\ApprovalRegistry;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Approvals\Models\ApprovalRequest;
use Twstec\Kit\Admin\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use Twstec\Kit\Admin\Resources\ApprovalRequests\Pages\ViewApprovalRequest;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\BaseResource;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * Aprovações — os pedidos da aprovação em dois passos (Approvals\ApprovalService).
 *
 * Somente leitura como registro (não se cria nem se edita pedido pela tela:
 * eles nascem da Action marcada). No detalhe ficam as decisões — aprovar
 * (com a ação sensível, quando exigida), recusar com motivo e, no modo de um
 * operador, executar depois da espera — cada uma conferida de novo pelo
 * serviço, sob trava.
 *
 * Permissões: `approvals.view` (ver), `approvals.approve`,
 * `approvals.reject`, `approvals.execute`, `approvals.delete` (excluir
 * pedido já encerrado) — e quem aprova ou executa
 * precisa também da permissão da própria ação (ex.: `users.delete`).
 */
final class ApprovalRequestResource extends BaseResource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static string $translationKey = 'admin.approvals';

    protected static ?string $navigationGroupKey = 'admin.nav.group_security';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, string|null>
     */
    public static function actionAbilities(): array
    {
        return [
            'approve' => 'approve',
            'confirmApprove' => 'approve',
            'reject' => 'reject',
            'execute' => 'execute',
            'delete' => 'delete',
        ];
    }

    /**
     * Pendentes no menu: é o que pede atenção.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! self::allows('view')) {
            return null;
        }

        $pending = ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function tableColumns(): array
    {
        return [
            TextColumn::make('action')
                ->label(__('admin.approvals.action'))
                ->formatStateUsing(fn (string $state): string => self::actionLabel($state)),
            TextColumn::make('status')
                ->label(__('panel.common.status'))
                ->badge()
                ->getStateUsing(fn (ApprovalRequest $record): ApprovalStatus => $record->displayStatus())
                ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                ->color(fn (ApprovalStatus $state): string => $state->color()),
            TextColumn::make('subject_uuid')
                ->label(__('admin.approvals.subject'))
                ->formatStateUsing(fn (ApprovalRequest $record): string => ($record->subject_type ?? '—').' · '.substr((string) $record->subject_uuid, 0, 8))
                ->fontFamily(FontFamily::Mono),
            TextColumn::make('requested_by_uuid')
                ->label(__('admin.approvals.requested_by'))
                ->formatStateUsing(fn (?string $state): string => self::personLabel($state)),
            AdminColumns::dateTime('created_at', __('admin.approvals.requested_at')),
            AdminColumns::dateTime('expires_at', __('admin.approvals.expires_at')),
        ];
    }

    protected static function tableExtras(Table $table): Table
    {
        return $table
            ->filters([
                SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(
                        fn (ApprovalStatus $status): array => [$status->value => $status->label()],
                    )->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.approvals.section_request'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('action')
                            ->label(__('admin.approvals.action'))
                            ->formatStateUsing(fn (string $state): string => self::actionLabel($state)),
                        TextEntry::make('status')
                            ->label(__('panel.common.status'))
                            ->badge()
                            ->getStateUsing(fn (ApprovalRequest $record): ApprovalStatus => $record->displayStatus())
                            ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                            ->color(fn (ApprovalStatus $state): string => $state->color()),
                        TextEntry::make('mode')
                            ->label(__('admin.approvals.mode'))
                            ->formatStateUsing(fn (ApprovalRequest $record): string => $record->mode->label()),
                        TextEntry::make('subject_uuid')
                            ->label(__('admin.approvals.subject'))
                            ->formatStateUsing(fn (ApprovalRequest $record): string => ($record->subject_type ?? '—').' · '.$record->subject_uuid)
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('requested_by_uuid')
                            ->label(__('admin.approvals.requested_by'))
                            ->formatStateUsing(fn (?string $state): string => self::personLabel($state)),
                        TextEntry::make('reason')
                            ->label(__('admin.approvals.reason')),
                        TextEntry::make('created_at')
                            ->label(__('admin.approvals.requested_at'))
                            ->dateTime('d/m/Y H:i', platform()->displayTimezone),
                        TextEntry::make('expires_at')
                            ->label(__('admin.approvals.expires_at'))
                            ->dateTime('d/m/Y H:i', platform()->displayTimezone),
                    ]),
                Section::make(__('admin.approvals.section_changes'))
                    ->description(__('admin.approvals.changes_hint'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('summary_rows')
                            ->hiddenLabel()
                            ->getStateUsing(fn (ApprovalRequest $record): array => self::summaryRows($record))
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
                Section::make(__('admin.approvals.section_decision'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('decided_by_uuid')
                            ->label(__('admin.approvals.decided_by'))
                            ->formatStateUsing(fn (?string $state): string => self::personLabel($state))
                            ->placeholder('—'),
                        TextEntry::make('decided_at')
                            ->label(__('admin.approvals.decided_at'))
                            ->dateTime('d/m/Y H:i', platform()->displayTimezone)
                            ->placeholder('—'),
                        TextEntry::make('decision_reason')
                            ->label(__('admin.approvals.decision_reason'))
                            ->placeholder('—'),
                        TextEntry::make('executable_after')
                            ->label(__('admin.approvals.executable_after'))
                            ->dateTime('d/m/Y H:i', platform()->displayTimezone)
                            ->placeholder('—'),
                        TextEntry::make('executed_at')
                            ->label(__('admin.approvals.executed_at'))
                            ->dateTime('d/m/Y H:i', platform()->displayTimezone)
                            ->placeholder('—'),
                        TextEntry::make('failure_reason')
                            ->label(__('admin.approvals.failure_reason'))
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public static function actionLabel(string $key): string
    {
        return app(ApprovalRegistry::class)->get($key)?->label() ?? $key;
    }

    /**
     * Quem pediu/decidiu: o e-mail da conta, ou o uuid curto se ela não
     * existe mais.
     */
    public static function personLabel(?string $uuid): string
    {
        if ($uuid === null || $uuid === '') {
            return '—';
        }

        $email = UserModel::query()->where('uuid', $uuid)->value('email');

        return is_string($email) ? $email : substr($uuid, 0, 8);
    }

    /**
     * @return list<array{field: string, before: string, after: string}>
     */
    public static function summaryRows(ApprovalRequest $record): array
    {
        $rows = [];

        foreach ($record->summary ?? [] as $field => $pair) {
            $rows[] = [
                'field' => (string) $field,
                'before' => self::display(is_array($pair) ? ($pair['before'] ?? null) : null),
                'after' => self::display(is_array($pair) ? ($pair['after'] ?? null) : $pair),
            ];
        }

        return $rows;
    }

    private static function display(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalRequests::route('/'),
            'view' => ViewApprovalRequest::route('/{record}'),
        ];
    }
}
