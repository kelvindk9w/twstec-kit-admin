<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Authorization\AdminRoles;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\Concerns\ConfirmsSensitiveActions;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * "Alterar papel" no detalhe e na edição do usuário — AÇÃO SENSÍVEL, em dois
 * modais encadeados (como ligar o segundo fator no perfil):
 *
 *   1. o papel novo (ou "sem acesso ao painel") + a senha de transação →
 *      código por e-mail;
 *   2. o código → token de uso único → AdminRoles::assign(), que confere de
 *      novo TODAS as regras (sem escalada, não o próprio, não acima de si,
 *      último dono) e consome o token.
 *
 * Aparece só para quem pode atribuir algum papel a esta conta; a recusa de
 * verdade é a do serviço, registrada na trilha (`user.role_changed`,
 * denied).
 */
trait AssignsAdminRole
{
    use ConfirmsSensitiveActions;

    /**
     * Valor do seletor para "sem papel" (o Select não guarda nulo como opção).
     */
    private const NO_ROLE = '__none__';

    public function assignRoleAction(): Action
    {
        return Action::make('assignRole')
            ->label(__('admin.roles.assign'))
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->authorize(fn (): bool => AdminPermissions::allows(auth()->user(), AdminRoles::PERMISSION))
            ->visible(fn (): bool => ! $this->roleTarget()->isReservedAccount()
                && auth()->id() !== $this->roleTarget()->getKey())
            ->modalHeading(__('admin.roles.assign_heading'))
            ->modalDescription(fn (): string => __('admin.roles.assign_description', [
                'current' => AdminPermissions::label(AdminPermissions::roleOf($this->roleTarget())),
            ]))
            ->schema([
                Select::make('role')
                    ->label(__('admin.roles.role'))
                    ->options(fn (): array => [self::NO_ROLE => __('admin.roles.none'), ...$this->grantableRoles()])
                    ->default(fn (): string => AdminPermissions::roleOf($this->roleTarget()) ?? self::NO_ROLE)
                    ->required()
                    ->selectablePlaceholder(false),
                $this->sensitivePasswordField(),
            ])
            ->modalSubmitActionLabel(__('panel.sensitive.send_code'))
            ->action(function (array $data): void {
                $role = $this->roleFrom($data['role'] ?? null);

                // As regras antes de mandar o código: recusa cedo, com registro.
                if ($motivo = app(AdminRoles::class)->denial(auth()->user(), $this->roleTarget(), $role)) {
                    AdminAudit::denied($motivo, $this->roleTarget(), AdminRoles::VERB, __('admin.users.action_denied'));

                    $this->haltRoleAction();
                }

                $this->sendSensitiveCode($data['transaction_password'] ?? '', AdminRoles::VERB, $this->roleTarget());

                $this->replaceMountedAction('confirmAssignRole', ['role' => $role ?? self::NO_ROLE]);
            });
    }

    public function confirmAssignRoleAction(): Action
    {
        return Action::make('confirmAssignRole')
            ->modalHeading(__('panel.sensitive.heading'))
            ->modalDescription(fn (array $arguments): string => __('admin.roles.confirm_description', [
                'role' => AdminPermissions::label($this->roleFrom($arguments['role'] ?? null)),
            ]))
            ->schema([$this->sensitiveCodeField()])
            ->modalSubmitActionLabel(__('panel.sensitive.confirm'))
            ->action(function (array $data, array $arguments): void {
                $role = $this->roleFrom($arguments['role'] ?? null);
                $token = $this->confirmSensitiveCode($data['code'] ?? '', AdminRoles::VERB, $this->roleTarget());

                try {
                    app(AdminRoles::class)->assign(auth()->user(), $this->roleTarget(), $role, $token);
                } catch (RecordedDenial $denial) {
                    AdminAudit::notifyRecorded($denial, __('admin.users.action_denied'));

                    $this->haltRoleAction();
                }

                Notification::make()
                    ->success()
                    ->title(__('admin.roles.assigned', ['role' => AdminPermissions::label($role)]))
                    ->send();

                $this->refreshFormData([AdminPermissions::COLUMN, 'is_admin']);
            });
    }

    /**
     * Os papéis que quem está logado pode conceder.
     *
     * @return array<string, string>
     */
    private function grantableRoles(): array
    {
        return array_filter(
            AdminPermissions::options(),
            fn (string $label, string $role): bool => AdminPermissions::canGrant(auth()->user(), $role),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function roleFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && $value !== self::NO_ROLE ? $value : null;
    }

    private function roleTarget(): Model&AuthUser
    {
        /** @var Model&AuthUser */
        return $this->getRecord();
    }

    private function haltRoleAction(): never
    {
        throw new Halt;
    }
}
