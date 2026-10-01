<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Authorization\Contracts;

/**
 * Tela do /admin cujas ações exigem permissão por papel.
 *
 * Todo resource que estende Support\BaseResource já implementa (a chave sai do
 * prefixo de tradução: `admin.users` → `users`). Uma PÁGINA própria do painel
 * (como Pages\Settings) implementa para entrar na mesma checagem central
 * (AdminAuthorization): abrir a tela pede `<chave>.view`; salvar,
 * `<chave>.update`; cada Action, `<chave>.<ação>`.
 */
interface GuardedByPermission
{
    /**
     * O recurso nas permissões (`users`, `settings`...).
     */
    public static function permissionKey(): string;

    /**
     * Nome da Action => ação na permissão, quando o padrão (o nome em
     * snake_case; `edit` → `update`) não serve. `null` = ação só de tela
     * (alternar visualização, abrir filtros), que não pede permissão.
     *
     * @return array<string, string|null>
     */
    public static function actionAbilities(): array;
}
