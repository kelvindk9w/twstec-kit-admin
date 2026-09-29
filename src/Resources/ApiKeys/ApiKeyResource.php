<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\ApiKeys;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Admin\Resources\ApiKeys\Pages\ListApiKeys;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\BaseResource;

/**
 * Chaves de API — visão GLOBAL de todos os tenants (super admin).
 *
 * Somente leitura + revogação administrativa (mesma operação do
 * ApiKeyService — nada duplicado). A secreta NUNCA aparece aqui
 * (no banco só existe o hash). Sem criar/editar: chaves nascem
 * pelo painel do próprio usuário ou pela API v1.
 */
final class ApiKeyResource extends BaseResource
{
    protected static ?string $model = ApiKey::class;

    protected static string $translationKey = 'admin.api_keys';

    protected static ?string $navigationGroupKey = 'admin.nav.group_management';

    // Posição no grupo "Gestão", de 10 em 10: a ordem do menu não pode
    // depender da ordem em que os resources são registrados (extensões, como
    // a demonstração do kit, registram os seus por outro caminho).
    protected static ?int $navigationSort = 10;

    // Navegação do /admin: TODO resource tem ícone (crítica de design #6 —
    // metade da nav aparecia como bolinha sem ícone).
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function tableColumns(): array
    {
        return [
            AdminColumns::publicCode(),
            TextColumn::make('name')
                ->label(__('panel.common.name'))
                ->searchable(),
            self::publicKeyColumn(),
            AdminColumns::account(),
            AdminColumns::accountOwner(__('admin.api_keys.owner')),
            AdminColumns::creator(),
            self::statusColumn(),
            AdminColumns::dateTime('last_used_at', __('admin.api_keys.last_used'))
                ->placeholder(__('admin.api_keys.never')),
            AdminColumns::dateTime('expires_at', __('admin.api_keys.expires_at'))
                ->placeholder(__('admin.api_keys.no_expiration')),
        ];
    }

    /**
     * Modo cards: o que se procura numa credencial — nome, se ainda vale,
     * de quem é, qual é a chave pública e quando foi usada pela última vez.
     */
    public static function cardComponents(): array
    {
        return [
            Stack::make([
                Split::make([
                    TextColumn::make('name')
                        ->label(__('panel.common.name'))
                        ->weight(FontWeight::SemiBold)
                        ->size(TextSize::Large)
                        ->searchable(),
                    self::statusColumn()->grow(false),
                ]),
                self::publicKeyColumn()
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->size(TextSize::Small),
                AdminColumns::accountOwner(__('admin.api_keys.owner'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->color('gray')
                    ->size(TextSize::Small),
                AdminColumns::dateTime('last_used_at', __('admin.api_keys.last_used'))
                    ->placeholder(__('admin.api_keys.never'))
                    ->size(TextSize::Small)
                    ->color('gray'),
            ])->space(2),
        ];
    }

    private static function publicKeyColumn(): TextColumn
    {
        return TextColumn::make('public_key')
            ->label(__('admin.api_keys.public_key'))
            ->copyable()
            ->searchable()
            ->limit(24);
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('panel.common.status'))
            ->badge()
            ->formatStateUsing(fn (ApiKeyStatus $state): string => match ($state) {
                ApiKeyStatus::Active => __('admin.api_keys.status_active'),
                ApiKeyStatus::Revoked => __('admin.api_keys.status_revoked'),
                ApiKeyStatus::Expired => __('admin.api_keys.status_expired'),
                ApiKeyStatus::ExpiredInactivity => __('admin.api_keys.status_expired_inactivity'),
                ApiKeyStatus::Rotated => __('admin.api_keys.status_rotated'),
            })
            ->color(fn (ApiKeyStatus $state): string => match ($state) {
                ApiKeyStatus::Active => 'success',
                ApiKeyStatus::Revoked => 'danger',
                ApiKeyStatus::Rotated => 'gray',
                default => 'warning',
            });
    }

    protected static function tableExtras(Table $table): Table
    {
        return $table
            ->filters([
                AdminColumns::accountFilter(),
                SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options([
                        ApiKeyStatus::Active->value => __('admin.api_keys.status_active'),
                        ApiKeyStatus::Revoked->value => __('admin.api_keys.status_revoked'),
                        ApiKeyStatus::Expired->value => __('admin.api_keys.status_expired'),
                        ApiKeyStatus::ExpiredInactivity->value => __('admin.api_keys.status_expired_inactivity'),
                        ApiKeyStatus::Rotated->value => __('admin.api_keys.status_rotated'),
                    ]),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('admin.api_keys.revoke'))
                    ->color('danger')
                    ->visible(fn (ApiKey $record): bool => $record->status === ApiKeyStatus::Active)
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.api_keys.revoke_heading'))
                    ->modalDescription(fn (ApiKey $record): string => __('admin.api_keys.revoke_warning', [
                        'key' => $record->public_key,
                        'owner' => (string) $record->account?->owner?->getAttribute('email'),
                    ]))
                    ->action(function (ApiKey $record, ApiKeyService $apiKeys): void {
                        $apiKeys->revoke($record);

                        Notification::make()->success()->title(__('admin.api_keys.revoked'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiKeys::route('/'),
        ];
    }
}
