<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Base da FAIXA DE KPIs de uma variante de dashboard.
 *
 * O widget concreto declara só `metrics(Period $period)` e devolve
 * MetricStat/Stat; daqui vêm o período da página, a largura total, a grade de
 * quatro colunas (o teto de densidade da primeira dobra) e o polling
 * desligado (dashboard de painel interno não precisa piscar a cada 5s).
 */
abstract class BaseStatsWidget extends StatsOverviewWidget
{
    use InteractsWithDashboardPeriod;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Quatro KPIs por linha: o limite de densidade de um dashboard legível
     * (4–6 números na primeira dobra).
     *
     * @return int|array<string, ?int>|null
     */
    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, '@xl' => 4, '!@lg' => 4];
    }

    /**
     * Os cards da faixa. Aceita MetricStat (o caminho normal) e Stat cru
     * (para números sem comparação, como um total absoluto).
     *
     * @return list<MetricStat|Stat>
     */
    abstract protected function metrics(Period $period): array;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $periodo = $this->period();

        return array_map(
            fn (MetricStat|Stat $card): Stat => $card instanceof MetricStat ? $card->toStat() : $card,
            $this->metrics($periodo),
        );
    }
}
