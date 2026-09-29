<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\ChartWidget;

/**
 * Base dos gráficos de COMPOSIÇÃO: "de que é feito este total?".
 *
 * Dois formatos, mesma classe: `doughnut` (participação — requisições por
 * faixa de status, uploads por tipo) e `bar` com `indexAxis: y` (ranking —
 * top endpoints). O widget concreto devolve `chartSlices(Period)`; cores,
 * legenda, estado vazio e a janela da página vêm de graça.
 */
abstract class BaseCompositionWidget extends ChartWidget
{
    use InteractsWithDashboardPeriod;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    /**
     * 'doughnut' (participação) ou 'bar' (ranking horizontal).
     */
    protected string $chartType = 'doughnut';

    /**
     * @return list<ChartSlice>
     */
    abstract protected function chartSlices(Period $period): array;

    protected function emptyIcon(): string|BackedEnum
    {
        return Heroicon::OutlinedChartPie;
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
        $fatias = $this->chartSlices($this->period());
        $neutras = StatusPalette::categorical();

        $cores = [];

        foreach ($fatias as $indice => $fatia) {
            $cores[] = $fatia->palette?->stroke() ?? $neutras[$indice % count($neutras)];
        }

        return [
            'datasets' => [[
                'label' => $this->getHeading() ?? '',
                'data' => array_map(fn (ChartSlice $fatia): float => $fatia->value, $fatias),
                'backgroundColor' => $cores,
                'borderColor' => $this->chartType === 'doughnut' ? 'transparent' : $cores,
                'borderWidth' => 0,
                'borderRadius' => $this->chartType === 'bar' ? 4 : 0,
                'maxBarThickness' => 22,
            ]],
            'labels' => array_map(fn (ChartSlice $fatia): string => $fatia->label, $fatias),
        ];
    }

    public function isEmpty(): bool
    {
        $valores = $this->getCachedData()['datasets'][0]['data'] ?? [];

        foreach ($valores as $valor) {
            if ((float) $valor !== 0.0) {
                return false;
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
        if ($this->chartType === 'doughnut') {
            return [
                'maintainAspectRatio' => false,
                'cutout' => '68%',
                'plugins' => [
                    'legend' => [
                        'position' => 'right',
                        'labels' => ['usePointStyle' => true, 'boxWidth' => 8, 'boxHeight' => 8, 'padding' => 14],
                    ],
                ],
            ];
        }

        return [
            'maintainAspectRatio' => false,
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'border' => ['display' => false],
                    'grid' => ['drawTicks' => false],
                    'ticks' => ['precision' => 0, 'maxTicksLimit' => 5],
                ],
                'y' => [
                    'grid' => ['display' => false],
                    'border' => ['display' => false],
                ],
            ],
        ];
    }
}
