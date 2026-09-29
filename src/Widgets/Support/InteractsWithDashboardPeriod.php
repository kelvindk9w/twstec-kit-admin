<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * O período é da PÁGINA, não do widget.
 *
 * O seletor 7/30/90 vive uma única vez, no filtro da variante de dashboard
 * (HasFiltersForm), e chega aqui por `$this->pageFilters` — reativo, então
 * trocar o período redesenha a página inteira de uma vez. Sem isto, cada
 * widget teria o seu próprio seletor e o dashboard mostraria janelas
 * diferentes lado a lado.
 */
trait InteractsWithDashboardPeriod
{
    use InteractsWithPageFilters;

    protected function period(): Period
    {
        return Period::fromFilters($this->pageFilters);
    }
}
