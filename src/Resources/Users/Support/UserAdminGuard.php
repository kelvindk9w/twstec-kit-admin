<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Support;

use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Kit;

/**
 * Guardas do CRUD de usuários do /admin.
 *
 * Regras — todas verificadas no SERVIDOR, não só escondendo botão:
 * 1. conta protegida (AccountProtection — com a demonstração do kit
 *    instalada, as contas demo) é intocável;
 * 2. o admin não se exclui nem se bloqueia (não existe "me tranquei fora");
 * 3. o último admin ATIVO não perde a flag, não é bloqueado e não é
 *    excluído — o painel ficaria sem dono e só o comando `user:make-admin`
 *    (que exige shell no servidor) recuperaria o acesso;
 * 4. quem é DONO de conta com outros membros não é excluído — a propriedade
 *    é transferida antes (twstec/kit-accounts; sem o pacote, não há contas
 *    e a regra não se aplica).
 *
 * Cada método devolve NULL quando a ação é permitida ou a mensagem
 * traduzida do motivo quando não é.
 */
final class UserAdminGuard
{
    /**
     * Pode editar este registro?
     */
    public static function editDenial(Model&AuthUser $record): ?string
    {
        return $record->isReservedAccount() ? __('admin.users.account_protected') : null;
    }

    /**
     * Pode marcar o e-mail deste registro como verificado (ação de suporte)?
     *
     * Conta protegida fica de fora como nas demais ações: ela já conta como
     * verificada enquanto a proteção vale (User::hasVerifiedEmail) e não é
     * o suporte quem mexe nela.
     */
    public static function verifyEmailDenial(Model&AuthUser $record): ?string
    {
        return $record->isReservedAccount() ? __('admin.users.account_protected') : null;
    }

    /**
     * Pode excluir este registro?
     */
    public static function deleteDenial(Model&AuthUser $record, (Model&AuthUser)|null $actor): ?string
    {
        if ($record->isReservedAccount()) {
            return __('admin.users.account_protected');
        }

        if ($actor !== null && $actor->getKey() === $record->getKey()) {
            return __('admin.users.cannot_delete_self');
        }

        if (self::isLastActiveAdmin($record)) {
            return __('admin.users.cannot_remove_last_admin');
        }

        // Dono de conta com outros membros: a propriedade é transferida antes
        // (a mesma regra que o pacote de contas aplica no model e no banco).
        return Kit::has('accounts') ? app(AccountService::class)->deletionDenial($record) : null;
    }

    /**
     * Pode bloquear este registro?
     */
    public static function blockDenial(Model&AuthUser $record, (Model&AuthUser)|null $actor): ?string
    {
        if ($record->isReservedAccount()) {
            return __('admin.users.account_protected');
        }

        if ($actor !== null && $actor->getKey() === $record->getKey()) {
            return __('admin.users.cannot_block_self');
        }

        if (self::isLastActiveAdmin($record)) {
            return __('admin.users.cannot_remove_last_admin');
        }

        return null;
    }

    /**
     * A alteração pretendida (status/is_admin) mantém o painel com dono?
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateDenial(Model&AuthUser $record, array $data, (Model&AuthUser)|null $actor): ?string
    {
        if ($record->isReservedAccount()) {
            return __('admin.users.account_protected');
        }

        $viraNaoAdmin = array_key_exists('is_admin', $data) && ! $data['is_admin'];
        $viraInativo = array_key_exists('status', $data)
            && UserStatus::from((string) $data['status']) !== UserStatus::Active;

        if (($viraNaoAdmin || $viraInativo) && self::isLastActiveAdmin($record)) {
            return __('admin.users.cannot_remove_last_admin');
        }

        if ($viraInativo && $actor !== null && $actor->getKey() === $record->getKey()) {
            return __('admin.users.cannot_block_self');
        }

        return null;
    }

    /**
     * Este é o ÚLTIMO admin ativo do sistema?
     */
    public static function isLastActiveAdmin(Model&AuthUser $record): bool
    {
        if (! $record->is_admin || ! $record->isActive()) {
            return false;
        }

        return UserModel::query()
            ->where('is_admin', true)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($record->getKey())
            ->doesntExist();
    }
}
