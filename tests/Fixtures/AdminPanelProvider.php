<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Twstec\Kit\Admin\AdminPlugin;

/**
 * O PanelProvider MÍNIMO de uma aplicação que instala o painel: id, caminho e
 * o plugin — nenhuma pilha de middleware, nenhuma proteção declarada aqui. Se
 * uma proteção só funcionasse porque o starter lembrou de ligá-la, a suíte
 * reprovaria.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Configuração extra do painel ANTES do plugin (cenário de um provider
     * copiado do modelo do Filament, com a própria pilha de middleware).
     *
     * @var (callable(Panel): Panel)|null
     */
    public static $before = null;

    /**
     * Configuração extra do painel DEPOIS do plugin.
     *
     * @var (callable(Panel): Panel)|null
     */
    public static $after = null;

    public function panel(Panel $panel): Panel
    {
        $panel = $panel->default()->id('admin')->path('admin');

        if (static::$before !== null) {
            $panel = (static::$before)($panel);
        }

        $panel = $panel->plugin(AdminPlugin::make());

        if (static::$after !== null) {
            $panel = (static::$after)($panel);
        }

        return $panel;
    }
}
