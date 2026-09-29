<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Dashboards;

use BackedEnum;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Twstec\Kit\Admin\Widgets\Support\Period;
use UnitEnum;

/**
 * Base das VARIANTES de dashboard do /admin.
 *
 * O kit entrega três (Visão Geral, Crescimento & API, Conteúdo & Operação) e
 * uma quarta nasce estendendo esta classe: a página concreta declara só o
 * slug e a lista de widgets. Daqui vêm:
 *
 * - a ROTA: a variante padrão (config `dashboards.default`) responde em
 *   /admin; as demais em /admin/dashboards/{slug};
 * - a NAVEGAÇÃO: grupo "Dashboards", ícone e ordem vindos da config, rótulo
 *   e título traduzidos por convenção (`admin.dashboards.{slug}.*`);
 * - o FILTRO DE PERÍODO (7/30/90) no topo, uma vez só: HasFiltersForm o
 *   propaga a todos os widgets da página por `$this->pageFilters` (ver
 *   InteractsWithDashboardPeriod). É o que garante que o card e o gráfico ao
 *   lado nunca mostrem janelas diferentes;
 * - a GRADE de 12 colunas no desktop, com os widgets declarando o seu span.
 */
abstract class BaseDashboard extends Dashboard
{
    use HasFiltersForm;

    /**
     * Slug da variante — a chave em config/dashboards.php.
     */
    abstract public static function variant(): string;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'dashboards/'.static::variant();
    }

    /**
     * A variante padrão é a HOME do painel; as demais moram em
     * /admin/dashboards/{slug}.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return DashboardRegistry::isDefault(static::variant())
            ? '/'
            : '/'.static::getSlug($panel);
    }

    /**
     * Chave-raiz das traduções da variante.
     */
    protected static function translationKey(): string
    {
        return 'admin.dashboards.'.static::variant();
    }

    public static function getNavigationLabel(): string
    {
        return __(static::translationKey().'.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.nav.group_dashboards');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return DashboardRegistry::icon(static::variant());
    }

    public static function getNavigationSort(): ?int
    {
        return DashboardRegistry::sort(static::variant());
    }

    public function getTitle(): string|Htmlable
    {
        return __(static::translationKey().'.title');
    }

    public function getSubheading(): ?string
    {
        return __(static::translationKey().'.subheading');
    }

    /**
     * Grade de 12 colunas no desktop (o vocabulário de span dos temas de
     * admin); duas colunas no tablet e uma no celular.
     *
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 12];
    }

    /**
     * O seletor de período da PÁGINA. Um controle só, no topo, valendo para
     * todos os widgets — em vez de um filtro repetido em cada gráfico.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                ToggleButtons::make('period')
                    ->label(__('admin.dashboards.filters.period'))
                    ->hiddenLabel()
                    ->inline()
                    ->grouped()
                    ->default(Period::defaultDays())
                    ->options(array_combine(
                        Period::options(),
                        array_map(
                            fn (int $dias): string => __('admin.dashboards.filters.period_option', ['days' => $dias]),
                            Period::options(),
                        ),
                    )),
            ]);
    }
}
