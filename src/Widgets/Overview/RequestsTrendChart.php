<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Overview;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Twstec\Kit\Admin\Widgets\Support\BaseTimeSeriesWidget;
use Twstec\Kit\Admin\Widgets\Support\ChartSeries;
use Twstec\Kit\Admin\Widgets\Support\Metric;
use Twstec\Kit\Admin\Widgets\Support\Period;
use Twstec\Kit\Admin\Widgets\Support\StatusPalette;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Tráfego da plataforma no período, com a linha de ERRO por cima: é o gráfico
 * que responde "está tudo bem?" antes de qualquer outro. Fonte: request_logs
 * (a trilha de auditoria append-only), o dado mais pronto do kit — o
 * RequestLogSeeder já semeia 30 dias realistas.
 */
final class RequestsTrendChart extends BaseTimeSeriesWidget
{
    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 2, 'xl' => 8];

    public function getHeading(): string
    {
        return __('admin.dashboards.overview.chart_requests_heading');
    }

    protected function emptyIcon(): string|BackedEnum
    {
        return Heroicon::OutlinedArrowsRightLeft;
    }

    /**
     * @return list<ChartSeries>
     */
    protected function chartSeries(Period $period): array
    {
        return [
            ChartSeries::make(
                __('admin.dashboards.overview.chart_requests_total'),
                Metric::count(fn () => RequestLog::query(), $period)->series(),
                StatusPalette::Neutral,
            ),
            ChartSeries::make(
                __('admin.dashboards.overview.chart_requests_errors'),
                Metric::count(
                    fn () => RequestLog::query()->where('http_status_response', '>=', 400),
                    $period,
                )->series(),
                StatusPalette::Danger,
            ),
        ];
    }
}
