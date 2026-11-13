<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Accounts\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimit;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimits;

/**
 * "Limites da API" no detalhe da conta: o que a PLATAFORMA dá a esta conta
 * (Tenancy\Limits\ApiLimits) — sobe ou baixa a cota por janela, a rajada
 * por minuto e a concorrência. Campo vazio = o padrão da configuração.
 *
 * - Permissão `accounts.set_api_limits` (o papel dono do painel tem `*`);
 *   conferida no servidor pela autorização central do /admin.
 * - Trilha: `account.api_limits_changed` com o antes/depois das colunas,
 *   pela captura central (AdminAudit; o verbo vem de AdminAudit::VERBS).
 * - A redução que o DONO da conta escolheu no painel continua valendo: o
 *   que vale é o menor dos dois.
 */
final class SetApiLimitsAction
{
    public const NAME = 'setApiLimits';

    public const VERB = 'api_limits_changed';

    public const AUDIT_ACTION = 'account.'.self::VERB;

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label(__('admin.accounts.api_limits_edit'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->modalHeading(__('admin.accounts.api_limits_heading'))
            ->modalDescription(__('admin.accounts.api_limits_description'))
            ->fillForm(fn (Account $record): array => app(ApiLimits::class)->platformOverrides($record))
            ->schema(array_map(static fn (ApiLimit $limit): TextInput => TextInput::make($limit->value)
                ->label($limit->label())
                ->integer()
                ->minValue(1)
                ->maxValue(ApiLimit::MAX)
                ->placeholder(__('admin.accounts.api_limits_default', ['value' => $limit->default()])), ApiLimit::cases()))
            ->modalSubmitActionLabel(__('admin.accounts.api_limits_save'))
            ->action(function (Account $record, array $data): void {
                $values = [];

                foreach (ApiLimit::cases() as $limit) {
                    $values[$limit->value] = $data[$limit->value] ?? null;
                }

                app(ApiLimits::class)->setPlatform($record, $values);

                Notification::make()->success()->title(__('admin.accounts.api_limits_saved'))->send();
            });
    }
}
