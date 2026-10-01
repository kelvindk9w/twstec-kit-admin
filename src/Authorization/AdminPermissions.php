<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Authorization;

use Filament\Actions\Action;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Twstec\Kit\Admin\Access\AdminAccess;

/**
 * O QUE cada pessoa pode fazer no /admin — o critério, em um lugar só.
 *
 * Entrar no painel é Access\AdminAccess (`is_admin` + conta ativa). Dentro
 * dele, cada ação pede uma PERMISSÃO `<recurso>.<ação>` (ex.: `users.block`,
 * `settings.update`, `approvals.approve`), e a permissão vem do PAPEL da
 * pessoa (coluna `admin_role`), declarado em `admin.authorization.roles`:
 *
 *   'roles' => [
 *       'owner'   => ['*'],
 *       'support' => ['users.view', 'users.mark_email_verified'],
 *       'auditor' => ['*.view'],
 *   ],
 *
 * Deny-by-default: sem papel, com papel que não está na lista ou com conta
 * que não entra no painel, nenhuma permissão. O papel de dono
 * (`super_role`) tem todas.
 *
 * Quem pergunta: as policies dos resources do kit (BaseResource — esconde o
 * que não pode), a checagem central de toda chamada Livewire do painel
 * (AdminAuthorization — recusa no SERVIDOR com 403 e linha `denied` na
 * trilha), as guardas de papel (AdminRoles) e a aprovação em dois passos.
 *
 * OPT-OUT: `admin.authorization.enabled = false` (ADMIN_AUTHORIZATION) volta
 * ao "tudo ou nada" de antes — todo admin pode tudo —, com aviso no log a
 * cada boot.
 */
final class AdminPermissions
{
    public const COLUMN = 'admin_role';

    /**
     * A checagem por papel está ligada?
     */
    public static function enabled(): bool
    {
        return config('admin.authorization.enabled', true) !== false;
    }

    /**
     * A pessoa tem a permissão?
     */
    public static function allows(?Authenticatable $user, string $permission): bool
    {
        if (! AdminAccess::allows($user)) {
            return false;
        }

        if (! self::enabled()) {
            return true;
        }

        return self::roleGrants(self::roleOf($user), $permission);
    }

    /**
     * O papel concede a permissão (sem olhar a pessoa)?
     */
    public static function roleGrants(?string $role, string $permission): bool
    {
        if ($role === null || ! self::exists($role)) {
            return false;
        }

        if ($role === self::superRole()) {
            return true;
        }

        foreach (self::patternsOf($role) as $pattern) {
            if (Str::is($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O papel gravado na conta (nulo quando não há ou não é texto).
     */
    public static function roleOf(?Authenticatable $user): ?string
    {
        if (! $user instanceof Model) {
            return null;
        }

        $role = $user->getAttribute(self::COLUMN);

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * A pessoa é dona do painel (papel `super_role`, e entra nele)?
     */
    public static function isSuper(?Authenticatable $user): bool
    {
        return AdminAccess::allows($user) && self::roleOf($user) === self::superRole();
    }

    public static function superRole(): string
    {
        return (string) config('admin.authorization.super_role', 'owner');
    }

    /**
     * Os papéis declarados, na ordem da configuração.
     *
     * @return list<string>
     */
    public static function roles(): array
    {
        $roles = array_keys((array) config('admin.authorization.roles', []));

        return array_values(array_filter($roles, static fn (mixed $role): bool => is_string($role) && $role !== ''));
    }

    public static function exists(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    /**
     * Os padrões de permissão de um papel (o dono, sempre `*`).
     *
     * @return list<string>
     */
    public static function patternsOf(string $role): array
    {
        if ($role === self::superRole()) {
            return ['*'];
        }

        $patterns = (array) (config('admin.authorization.roles', [])[$role] ?? []);

        return array_values(array_filter($patterns, static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== ''));
    }

    /**
     * Rótulo do papel na tela: `admin.roles.<papel>`, ou o próprio nome.
     */
    public static function label(?string $role): string
    {
        if ($role === null) {
            return __('admin.roles.none');
        }

        $key = 'admin.roles.'.$role;
        $label = __($key);

        return $label === $key ? Str::headline($role) : $label;
    }

    /**
     * Opções do seletor de papel: papel => rótulo.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::roles() as $role) {
            $options[$role] = self::label($role);
        }

        return $options;
    }

    /**
     * SEM ESCALADA: quem pode CONCEDER este papel? Só quem tem cada uma das
     * permissões que ele dá. O papel de dono só o dono concede.
     *
     * A comparação é entre PADRÕES, não contra a lista de permissões de hoje:
     * `users.*` só é concedido por quem tem `users.*` (ou `*`) — quem tem só
     * `users.view` e `users.update` não, porque o curinga daria também as
     * ações que ainda vão existir. Um padrão P está coberto por Q quando Q,
     * lido como curinga, casa com o texto de P (inclusive o `*` dele).
     */
    public static function canGrant(?Authenticatable $actor, ?string $role): bool
    {
        if ($role === null) {
            return true;
        }

        if (! self::exists($role) || ! AdminAccess::allows($actor)) {
            return false;
        }

        if (self::isSuper($actor)) {
            return true;
        }

        if ($role === self::superRole()) {
            return false;
        }

        $mine = self::patternsOf((string) self::roleOf($actor));

        foreach (self::patternsOf($role) as $pattern) {
            $covered = false;

            foreach ($mine as $own) {
                if (Str::is($own, $pattern)) {
                    $covered = true;

                    break;
                }
            }

            if (! $covered) {
                return false;
            }
        }

        return true;
    }

    /**
     * Esconde a Action de quem não tem a permissão (conforto de tela — a
     * recusa de verdade é a checagem central no servidor, AdminAuthorization).
     * Para ações de cabeçalho e de páginas próprias; nas tabelas dos
     * resources do kit isso já é automático (BaseResource).
     */
    public static function guard(Action $action, string $permission): Action
    {
        return $action->authorize(fn (): bool => self::allows(auth()->user(), $permission));
    }
}
