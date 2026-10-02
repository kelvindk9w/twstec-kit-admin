<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Support;

use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Deletion\AccountDeletion;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
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
 *    é transferida antes — e quem tem IMPEDIMENTO DE EXCLUSÃO declarado pelo
 *    aplicativo também não (twstec/kit-accounts, Deletion\DeletionImpediments;
 *    sem o pacote, não há contas e a regra não se aplica);
 * 5. o último DONO do painel ativo (papel `super_role`) também não perde a
 *    flag, não é bloqueado nem excluído — sem ele, ninguém mais atribui
 *    papel nem mexe no que só o dono pode;
 * 6. conceder ou tirar a flag de acesso pelo formulário pede a permissão de
 *    atribuir papel (`users.assign_role`), e quem a concede não reativa
 *    um papel que não poderia atribuir (ver AdminPermissions::canGrant).
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

        if (self::isLastActiveAdmin($record) || self::isLastActiveSuperAdmin($record)) {
            return __('admin.users.cannot_remove_last_admin');
        }

        // Dono de conta com outros membros: a propriedade é transferida antes
        // (a mesma regra que o pacote de contas aplica no model e no banco).
        return Kit::has('accounts') ? app(AccountService::class)->deletionDenial($record) : null;
    }

    /**
     * Exclui a pessoa (já autorizada pela pré-checagem) e devolve `true` — ou
     * LANÇA a RECUSA, já gravada na trilha, quando ela é recusada na hora:
     * impedimento declarado que surgiu depois da tela, dona de conta com
     * membros, ou um registro do aplicativo que aponta para a pessoa (chave
     * estrangeira RESTRICT) — com a exclusão desfeita, nada apagado. A tela
     * só avisa (AdminAudit::notifyRecorded).
     *
     * Com o twstec/kit-accounts, a exclusão é a do CAMINHO ÚNICO
     * (Deletion\AccountDeletion::deleteUser), que grava a recusa. `false` =
     * um ouvinte do aplicativo cancelou sem exceção.
     *
     * @throws RecordedDenial
     */
    public static function delete(Model&AuthUser $record): bool
    {
        if (! Kit::has('accounts')) {
            return (bool) $record->delete();
        }

        $deletion = app(AccountDeletion::class);

        try {
            return $deletion->deleteUser($record);
        } catch (DeletionImpededException|OwnerOfSharedAccountException $exception) {
            $event = $deletion->recordedRefusal($exception);

            if ($event === null) {
                throw $exception;
            }

            throw RecordedDenial::recorded($event, $exception->getMessage());
        }
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

        if (self::isLastActiveAdmin($record) || self::isLastActiveSuperAdmin($record)) {
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

        if (($viraNaoAdmin || $viraInativo) && (self::isLastActiveAdmin($record) || self::isLastActiveSuperAdmin($record))) {
            return __('admin.users.cannot_remove_last_admin');
        }

        $mudaAcesso = array_key_exists('is_admin', $data) && (bool) $data['is_admin'] !== (bool) $record->getAttribute('is_admin');

        if ($mudaAcesso && ($motivo = self::accessFlagDenial($record, (bool) $data['is_admin'], $actor))) {
            return $motivo;
        }

        if ($viraInativo && $actor !== null && $actor->getKey() === $record->getKey()) {
            return __('admin.users.cannot_block_self');
        }

        return null;
    }

    /**
     * Pode conceder (ou tirar) a flag de acesso ao painel pelo formulário?
     *
     * Conceder só dá ENTRADA (sem papel, nenhuma permissão); tirar também tira
     * o papel. As duas pedem `users.assign_role`. E conceder a quem guardou
     * um papel que o ator não poderia atribuir reativaria esse papel — recusado.
     */
    public static function accessFlagDenial(Model&AuthUser $record, bool $grant, (Model&AuthUser)|null $actor): ?string
    {
        if (! AdminPermissions::allows($actor, 'users.assign_role')) {
            return __('admin.authorization.denied', ['permission' => 'users.assign_role']);
        }

        if ($actor !== null && $actor->getKey() === $record->getKey()) {
            return __('admin.roles.cannot_change_own');
        }

        if ($grant && ! AdminPermissions::canGrant($actor, AdminPermissions::roleOf($record))) {
            return __('admin.roles.cannot_grant');
        }

        return null;
    }

    /**
     * Este é o último DONO do painel ativo (papel `super_role`)?
     */
    public static function isLastActiveSuperAdmin(Model&AuthUser $record): bool
    {
        if (! AdminPermissions::isSuper($record)) {
            return false;
        }

        return UserModel::query()
            ->where('is_admin', true)
            ->where('status', UserStatus::Active->value)
            ->where(AdminPermissions::COLUMN, AdminPermissions::superRole())
            ->whereKeyNot($record->getKey())
            ->doesntExist();
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
