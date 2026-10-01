<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Twstec\Kit\Admin\Resources\Users\Support\UserAdminGuard;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\AuditTrail;

/**
 * ATRIBUIR ou RETIRAR o papel de alguém no /admin — a barreira de servidor.
 *
 * É ação sensível: só com o token de uso único da confirmação do kit (senha
 * de transação + código por e-mail), consumido aqui. E é a porta da
 * escalada, então as regras moram aqui e não na tela:
 *
 * - quem atribui precisa de `users.assign_role`;
 * - ninguém muda o próprio papel;
 * - só se concede papel cujas permissões o próprio ator tem
 *   (AdminPermissions::canGrant) — o papel de dono, só o dono concede;
 * - não se mexe em quem está acima: o papel ATUAL do alvo também tem de ser
 *   um que o ator poderia conceder (o operador não rebaixa o dono);
 * - o último dono ativo não é rebaixado (o painel ficaria sem dono);
 * - conta protegida (AccountProtection) é intocável.
 *
 * Papel nulo = retirar: a pessoa perde também a entrada no painel
 * (`is_admin` = false). Papel definido = entrada + papel. Tudo vai para a
 * trilha como `user.role_changed` (de/para do papel e da flag); a recusa,
 * como `denied` com o motivo — antes de a tela saber dela (RecordedDenial).
 */
final class AdminRoles
{
    public const VERB = 'role_changed';

    public const PERMISSION = 'users.assign_role';

    public function __construct(
        private readonly SensitiveActionService $sensitiveActions,
        private readonly AuditTrail $trail,
    ) {}

    /**
     * Motivo da recusa, ou null quando a atribuição é permitida (sem olhar a
     * confirmação sensível — a tela usa para esconder o que não pode).
     */
    public function denial(?Authenticatable $actor, Model&AuthUser $target, ?string $role): ?string
    {
        if (! AdminPermissions::allows($actor, self::PERMISSION)) {
            return __('admin.authorization.denied', ['permission' => self::PERMISSION]);
        }

        if ($target->isReservedAccount()) {
            return __('admin.users.account_protected');
        }

        if ($actor instanceof Model && $actor->getKey() === $target->getKey()) {
            return __('admin.roles.cannot_change_own');
        }

        if ($role !== null && ! AdminPermissions::exists($role)) {
            return __('admin.roles.unknown');
        }

        if (! AdminPermissions::canGrant($actor, $role)) {
            return __('admin.roles.cannot_grant');
        }

        $current = AdminPermissions::roleOf($target);

        if ($current !== null && ! AdminPermissions::canGrant($actor, $current)) {
            return __('admin.roles.cannot_change_higher');
        }

        if ($role !== AdminPermissions::superRole() && UserAdminGuard::isLastActiveSuperAdmin($target)) {
            return __('admin.roles.last_super');
        }

        if ($role === null && UserAdminGuard::isLastActiveAdmin($target)) {
            return __('admin.users.cannot_remove_last_admin');
        }

        return null;
    }

    /**
     * Atribui (ou retira, com `$role` nulo) o papel.
     *
     * @throws RecordedDenial recusa já registrada na trilha
     */
    public function assign(Authenticatable $actor, Model&AuthUser $target, ?string $role, #[SensitiveParameter] ?string $sensitiveToken): void
    {
        AdminAudit::within(self::VERB, function () use ($actor, $target, $role, $sensitiveToken): void {
            $denial = $this->denial($actor, $target, $role);

            // O token só é consumido quando todo o resto passou.
            if ($denial === null
                && ($sensitiveToken === null || ! $actor instanceof AuthUser || ! $this->sensitiveActions->validateToken($actor, $sensitiveToken))) {
                $denial = __('admin.sensitive.required');
            }

            if ($denial !== null) {
                throw RecordedDenial::recorded($this->trail->denied('user.'.self::VERB, $target, $denial), $denial);
            }

            DB::transaction(fn (): bool => $target->forceFill([
                AdminPermissions::COLUMN => $role,
                'is_admin' => $role !== null,
            ])->save());
        }, $actor);
    }
}
