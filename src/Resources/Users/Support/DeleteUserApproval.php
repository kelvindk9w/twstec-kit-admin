<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Approvals\ApprovableAction;
use Twstec\Kit\Admin\Approvals\ExecutionRefused;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * EXCLUIR USUÁRIO com aprovação em dois passos — o exemplo do kit, atrás de
 * config: ADMIN_APPROVALS_ACTIONS=users.delete.
 *
 * Ligado, o "Excluir" da listagem e da edição (UserResource::deleteAction)
 * pede o motivo e cria o pedido; outra pessoa com `approvals.approve` e
 * `users.delete` aprova na tela "Aprovações", e só então a conta sai.
 *
 * As guardas são as MESMAS do caminho direto (UserAdminGuard::deleteDenial):
 * conta protegida, excluir a si mesmo, último admin/dono, dono de conta com
 * membros, impedimento de exclusão declarado pelo aplicativo — conferidas no
 * pedido (com quem pede: o impedimento já recusa o pedido), de novo na
 * aprovação (com quem aprova: ninguém aprova a exclusão da própria conta) e,
 * na EXECUÇÃO, a recusa que só aparece na hora (impedimento novo, registro
 * do aplicativo com chave estrangeira RESTRICT) vira pedido `failed` com a
 * mensagem traduzida, nada apagado (UserAdminGuard::delete — o caminho
 * único de exclusão do twstec/kit-accounts, que grava a recusa na trilha).
 *
 * O retrato do estado olha o que muda o sentido da exclusão: e-mail,
 * situação, flag de acesso e papel. Se algum mudou depois do pedido, ele
 * fica obsoleto e não executa.
 */
final class DeleteUserApproval extends ApprovableAction
{
    public const KEY = 'users.delete';

    public function key(): string
    {
        return self::KEY;
    }

    public function subjectModel(): string
    {
        return UserModel::name();
    }

    public function label(): string
    {
        return __('admin.approvals.action_users_delete');
    }

    public function denial(Model $subject, array $data, Authenticatable $actor): ?string
    {
        if (! $subject instanceof AuthUser || ! $actor instanceof AuthUser || ! $actor instanceof Model) {
            return __('admin.approvals.subject_missing');
        }

        return UserAdminGuard::deleteDenial($subject, $actor);
    }

    public function changes(Model $subject, array $data): array
    {
        return [
            'email' => ['before' => $subject->getAttribute('email'), 'after' => null],
            'name' => ['before' => $subject->getAttribute('name'), 'after' => null],
            'status' => ['before' => $subject->getAttribute('status'), 'after' => null],
            AdminPermissions::COLUMN => ['before' => $subject->getAttribute(AdminPermissions::COLUMN), 'after' => null],
        ];
    }

    public function fingerprint(Model $subject, array $data): array
    {
        return [
            'email' => $subject->getAttribute('email'),
            'status' => $subject->getAttribute('status'),
            'is_admin' => (bool) $subject->getAttribute('is_admin'),
            AdminPermissions::COLUMN => $subject->getAttribute(AdminPermissions::COLUMN),
        ];
    }

    /**
     * A exclusão passa pelos MESMOS impedimentos do caminho direto, de novo
     * na hora: impedimento declarado que surgiu depois do pedido, dona de
     * conta com membros, ou registro do aplicativo que aponta para a pessoa
     * (chave estrangeira RESTRICT, não declarada) → ExecutionRefused com a
     * mensagem traduzida; o pedido fica `failed`, nada sai.
     */
    public function execute(Model $subject, array $data, Authenticatable $actor): void
    {
        if (! $subject instanceof AuthUser) {
            throw new ExecutionRefused(__('admin.approvals.subject_missing'));
        }

        try {
            UserAdminGuard::delete($subject);
        } catch (RecordedDenial $recusa) {
            // O caminho único de exclusão já gravou a recusa na trilha.
            throw new ExecutionRefused($recusa->getMessage(), recorded: true);
        }
    }

    public function verb(): string
    {
        return 'deleted';
    }
}
