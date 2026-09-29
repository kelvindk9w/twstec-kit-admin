<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * QUEM entra no /admin — o critério, em um lugar só.
 *
 * Deny-by-default: só a conta com a flag `is_admin` (concedida pelo comando
 * `user:make-admin` ou por outro admin no próprio painel) E com a conta
 * ATIVA (twstec/kit-auth). Os demais recebem 403.
 *
 * Quem pergunta: o model de usuário do aplicativo (`canAccessPanel`, pela
 * trait Concerns\AccessesAdminPanel), o middleware do pacote
 * (Http\Middleware\EnsureAdminPanelAccess — vale mesmo que o model não
 * implemente o contrato do Filament) e o gate do dashboard de filas do
 * aplicativo (`viewHorizon`, no starter). Revogar o acesso tem de revogar em
 * todas as superfícies, por isso todas leem daqui.
 */
final class AdminAccess
{
    public static function allows(?Authenticatable $user): bool
    {
        if (! $user instanceof AuthUser || ! $user instanceof Model) {
            return false;
        }

        return (bool) $user->getAttribute('is_admin') && $user->isActive();
    }
}
