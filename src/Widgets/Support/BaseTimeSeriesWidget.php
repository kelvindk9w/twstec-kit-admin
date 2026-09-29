<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\ChartWidget;

/**
 * Base de TODO gráfico de série temporal do painel (linha, área ou barras).
 *
 * O widget concreto responde três coisas — título, ícone do estado vazio e
 * `chartSeries(Period)` — e recebe pronto: janela vinda do filtro da página,
 * eixo X com um rótulo por dia, opções do Chart.js já ajustadas para leitura
 * (sem grade vertical, sem pontos, legenda só quando há mais de uma série) e
 * estado vazio ILUSTRADO e traduzido quando o período não tem movimento.
 *
 * Nasce um gráfico novo em ~15 linhas: declarar o tipo, o título e devolver
 * `ChartSeries::make(rótulo, $metric->series(), StatusPalette::X)`.
 */
abstract class BaseTimeSeriesWidget extends ChartWidget
{
    use InteractsWithDashboardPeriod;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    /**
     * 'line' (com ou sem área) ou 'bar'.
     */
    protected string $chartType = 'line';

    /**
     * @return list<ChartSeries>
     */
    abstract protected function chartSeries(Period $period): array;

    /**
     * Ícone do estado vazio — o assunto do gráfico, nunca um "X" genérico.
     */
    protected function emptyIcon(): string|BackedEnum
    {
        return Heroicon::OutlinedChartBar;
    }

    protected function getType(): string
    {
        return $this->chartType;
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboards.common.period_window', ['days' => $this->period()->days]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $periodo = $this->period();
        $series = $this->chartSeries($periodo);

        return [
            'datasets' => array_map(
                fn (ChartSeries $serie): array => $serie->toDataset($this->getType()),
                $series,
            ),
            'labels' => $periodo->labels(),
        ];
    }

    /**
     * Um gráfico sem NENHUM movimento é um gráfico vazio: melhor o estado
     * ilustrado do que uma reta no zero fingindo informação.
     */
    public function isEmpty(): bool
    {
        $dados = $this->getCachedData();

        foreach ($dados['datasets'] ?? [] as $dataset) {
            foreach ($dataset['data'] ?? [] as $valor) {
                if ((float) $valor !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }

    public function getEmptyStateHeading(): string
    {
        return __('admin.dashboards.common.empty_chart_heading');
    }

    public function getEmptyStateDescription(): string
    {
        return __('admin.dashboards.common.empty_chart_description');
    }

    public function getEmptyStateIcon(): string|BackedEnum
    {
        return $this->emptyIcon();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        $multiplasSeries = count($this->getCachedData()['datasets'] ?? []) > 1;

        return [
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => [
                    'display' => $multiplasSeries,
                    'position' => 'bottom',
                    'labels' => ['usePointStyle' => true, 'boxWidth' => 8, 'boxHeight' => 8, 'padding' => 16],
                ],
                'tooltip' => ['displayColors' => $multiplasSeries],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => ['maxRotation' => 0, 'autoSkip' => true, 'maxTicksLimit' => 8],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'border' => ['display' => false],
                    'grid' => ['drawTicks' => false],
                    'ticks' => ['precision' => 0, 'maxTicksLimit' => 5, 'padding' => 8],
                ],
            ],
        ];
    }
}
