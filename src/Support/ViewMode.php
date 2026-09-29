<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

/**
 * Modo de exibição de uma listagem do super admin: tabela clássica ou grade
 * de cards.
 *
 * DECISÃO DE PERSISTÊNCIA (documentada em docs/admin-e-dashboards.md): a escolha vive na
 * SESSÃO, com uma chave por RECURSO. Motivos:
 *
 * - a sessão já é por usuário — não precisa de coluna nova, migration nem
 *   escrita no banco a cada clique num controle de visualização;
 * - a preferência é de trabalho, não de conta: quem audita submissões em
 *   cards continua querendo os request logs em tabela, e a chave por
 *   recurso garante isso;
 * - trocar de máquina reinicia no padrão (tabela), que é o modo dense —
 *   o certo para quem abre o painel para procurar alguma coisa.
 *
 * Se algum dia a preferência tiver de atravessar sessões, o ponto de troca
 * é só este arquivo: `for()` e `store()` passam a ler/gravar uma coluna
 * `admin_preferences` do usuário e nada mais no painel muda.
 */
enum ViewMode: string
{
    case Table = 'table';

    case Grid = 'grid';

    /**
     * Chave de sessão do recurso (a sessão já isola por usuário).
     */
    public static function sessionKey(string $resource): string
    {
        return 'admin.view_mode.'.str_replace('\\', '.', $resource);
    }

    /**
     * Modo vigente do recurso. Padrão: tabela.
     *
     * @param  class-string  $resource
     */
    public static function for(string $resource): self
    {
        // Escolha feita antes da 2.0, gravada pelo nome antigo do resource.
        LegacySessionState::migrateViewMode($resource);

        $stored = session()->get(self::sessionKey($resource));

        return is_string($stored) ? (self::tryFrom($stored) ?? self::Table) : self::Table;
    }

    /**
     * @param  class-string  $resource
     */
    public static function store(string $resource, self $mode): void
    {
        session()->put(self::sessionKey($resource), $mode->value);
    }

    public function toggled(): self
    {
        return $this === self::Table ? self::Grid : self::Table;
    }

    public function isGrid(): bool
    {
        return $this === self::Grid;
    }
}
