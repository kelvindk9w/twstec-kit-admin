<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Panel;
use Livewire\Livewire;
use Twstec\Kit\Admin\AdminPlugin;

/**
 * As garantias de segurança de um painel que registra o AdminPlugin, aplicadas
 * pelo PACOTE — não pela ordem em que o aplicativo escreveu o PanelProvider.
 *
 * - A barreira de ORIGEM (allowlist de IP) é o PRIMEIRO middleware do painel:
 *   requisição de origem não permitida é recusada antes de a sessão ser
 *   aberta, o CSRF processado ou o painel montado (no fim da lista, um IP
 *   barrado ainda ganhava cookie de sessão e passava pelo pipeline inteiro).
 *   E é PERSISTENTE: as ações dos componentes não chegam pelas rotas do
 *   painel, e sim pelo endpoint de atualização do Livewire — uma rota única,
 *   fora do prefixo do painel, que só reaplica os middlewares marcados como
 *   persistentes. Sem isso, a allowlist valia para abrir a página, mas não
 *   para executar a ação dela.
 * - O acesso só de admin com conta ativa (Authenticate do Filament +
 *   EnsureAdminPanelAccess do pacote) fica na autenticação do painel, também
 *   persistente.
 * - Nenhum middleware da pilha entra duas vezes (um PanelProvider copiado do
 *   modelo do Filament declara a mesma pilha de sessão que o plugin traz, e
 *   iniciar a sessão duas vezes não é inofensivo).
 * - Actions e Criar/Salvar em transação (a trilha de auditoria falha FECHADA).
 *
 * Roda duas vezes: no register do plugin e de novo depois que o Filament
 * montou todos os painéis (AdminServiceProvider), quando o aplicativo já
 * terminou de configurar o dele. É idempotente.
 *
 * Opt-out: `admin.protections = false` (ADMIN_PROTECTIONS) — só a
 * desduplicação continua; o aviso no log é do AdminServiceProvider.
 */
final class AdminPanelHardening
{
    public static function apply(Panel $panel): void
    {
        $protect = self::enabled();
        $barrier = AdminPlugin::originBarrier();
        $auth = AdminPlugin::authMiddleware();

        (function () use ($protect, $barrier, $auth): void {
            /** @var Panel $this */
            $middleware = array_values(array_unique($this->middleware, SORT_REGULAR));
            $authMiddleware = array_values(array_unique($this->authMiddleware, SORT_REGULAR));

            if ($protect) {
                $middleware = [$barrier, ...array_values(array_filter($middleware, fn (mixed $item): bool => $item !== $barrier))];

                foreach ($auth as $required) {
                    if (! in_array($required, $authMiddleware, true)) {
                        $authMiddleware[] = $required;
                    }
                }
            }

            $this->middleware = $middleware;
            $this->authMiddleware = $authMiddleware;
        })->call($panel);

        if (! $protect) {
            return;
        }

        // Persistentes no endpoint do Livewire. O Panel entrega a lista dele
        // ao Livewire quando é registrado; depois disso, só direto.
        $panel->persistentMiddleware([$barrier, ...$auth]);
        Livewire::addPersistentMiddleware([$barrier, ...$auth]);

        if (! $panel->hasDatabaseTransactions()) {
            $panel->databaseTransactions();
        }
    }

    /**
     * O painel tem as garantias? (Usado pelos testes e pela conferência do
     * provider.)
     */
    public static function holds(Panel $panel): bool
    {
        $middleware = $panel->getMiddleware();

        return ($middleware[1] ?? null) === AdminPlugin::originBarrier()
            && count($middleware) === count(array_unique($middleware))
            && array_diff(AdminPlugin::authMiddleware(), $panel->getAuthMiddleware()) === []
            && $panel->hasDatabaseTransactions();
    }

    public static function enabled(): bool
    {
        return config('admin.protections', true) !== false;
    }
}
