<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Illuminate\Contracts\Session\Session;
use Twstec\Kit\Admin\Compat\LegacyNames;

/**
 * Estado do painel guardado NA SESSÃO pelo nome da classe — migrado do nome
 * antigo (App\Filament\…, 1.x) para o novo (Twstec\Kit\Admin\…) na primeira
 * vez que a tela é aberta depois da atualização.
 *
 * Duas fontes gravam assim:
 *
 * - o FILAMENT, por tabela (listagens e widgets de tabela): itens por página,
 *   ordenação, filtros, busca, colunas escolhidas e agrupamento, na chave
 *   `tables.<md5 da classe>_<estado>`;
 * - o PAINEL, por resource: o modo tabela/cards (ViewMode), na chave
 *   `admin.view_mode.<classe com pontos>`.
 *
 * Sem a migração, quem estava logado durante a atualização perderia essas
 * escolhas (o nome da classe mudou, a chave também). A migração é
 * IDEMPOTENTE: só copia quando o nome novo ainda não tem valor (uma escolha
 * feita depois da atualização vence) e apaga a chave antiga, então rodar de
 * novo não muda nada.
 */
final class LegacySessionState
{
    /**
     * Sufixos das chaves de estado de tabela do Filament 5.
     */
    public const TABLE_SUFFIXES = [
        'per_page',
        'sort',
        'filters',
        'search',
        'column_search',
        'columns',
        'has_reordered_columns',
        'grouping',
    ];

    /**
     * Migra o estado de tabela da classe `$class` (componente de tabela).
     */
    public static function migrateTable(string $class, ?Session $session = null): void
    {
        $old = LegacyNames::previous($class);

        if ($old === null) {
            return;
        }

        $session ??= self::session();

        if ($session === null) {
            return;
        }

        foreach (self::TABLE_SUFFIXES as $suffix) {
            self::move($session, 'tables.'.md5($old).'_'.$suffix, 'tables.'.md5($class).'_'.$suffix);
        }
    }

    /**
     * Migra o modo de visualização do resource `$resource`.
     */
    public static function migrateViewMode(string $resource, ?Session $session = null): void
    {
        $old = LegacyNames::previous($resource);

        if ($old === null) {
            return;
        }

        $session ??= self::session();

        if ($session === null) {
            return;
        }

        self::move($session, ViewMode::sessionKey($old), ViewMode::sessionKey($resource));
    }

    private static function move(Session $session, string $from, string $to): void
    {
        if (! $session->has($from)) {
            return;
        }

        if (! $session->has($to)) {
            $session->put($to, $session->get($from));
        }

        $session->forget($from);
    }

    /**
     * A MESMA sessão que o Filament e o ViewMode usam (o helper `session()`).
     */
    private static function session(): ?Session
    {
        return app()->bound('session.store') ? app('session.store') : null;
    }
}
