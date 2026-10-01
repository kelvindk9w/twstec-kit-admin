<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Access;

use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Contracts\IdentifiesAdministrators;

/**
 * Quem é "administrador" para o segundo fator obrigatório
 * (`AUTH_TWO_FACTOR_REQUIRED=admins`, regra do twstec/kit-auth).
 *
 * Toda conta que entra no /admin (`is_admin`) OU tem algum papel do painel
 * gravado (`admin_role`: owner, operations, support, auditor ou os que a
 * aplicação declarar) — QUALQUER papel. Até o papel mais restrito (auditor,
 * só leitura) enxerga dados pessoais de todas as contas e a trilha de
 * auditoria; a regra existe justamente para essas sessões.
 *
 * A conta não precisa estar ativa nem ter um papel que ainda exista na
 * configuração: o critério erra para o lado de EXIGIR (uma conta reativada ou
 * um papel recriado já encontram a exigência no lugar).
 */
final class PanelAdministrators implements IdentifiesAdministrators
{
    public function isAdministrator(AuthUser $user): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        return (bool) $user->getAttribute('is_admin') || AdminPermissions::roleOf($user) !== null;
    }
}
