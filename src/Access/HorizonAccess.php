<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Twstec\Kit\Admin\Authorization\AdminPermissions;

/**
 * QUEM abre o dashboard de filas (`/horizon`) — o critério do gate
 * `viewHorizon` do aplicativo (HorizonServiceProvider do starter).
 *
 * Entrar no /admin (AdminAccess) não basta: o Horizon mostra o payload dos
 * jobs (dado pessoal) e deixa refazer e apagar job — não é coisa do suporte.
 * Pede a permissão `horizon.access` do papel (AdminPermissions), que de
 * fábrica só o `owner` (tudo) e a `operations` têm. O nome NÃO termina em
 * `.view` de propósito: o dashboard não tem modo só leitura, e `*.view` (o
 * papel de auditoria) não pode abri-lo por tabela.
 *
 * Conta bloqueada ou sem a flag de admin continua de fora (AdminPermissions
 * confere AdminAccess antes do papel). Com ADMIN_AUTHORIZATION=false, volta o
 * tudo-ou-nada: todo admin ativo abre.
 */
final class HorizonAccess
{
    public const PERMISSION = 'horizon.access';

    public static function allows(?Authenticatable $user): bool
    {
        return AdminPermissions::allows($user, self::PERMISSION);
    }
}
