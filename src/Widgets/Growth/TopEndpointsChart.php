<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Growth;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Twstec\Kit\Admin\Widgets\Support\BaseCompositionWidget;
use Twstec\Kit\Admin\Widgets\Support\ChartSlice;
use Twstec\Kit\Admin\Widgets\Support\Period;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Ranking dos endpoints mais chamados no período (barras horizontais).
 *
 * DIVERGÊNCIA CONSCIENTE da pesquisa, que propunha "top 5 chaves de API por
 * volume": `request_logs` guarda o TENANT da requisição (uuid do dono da
 * chave), não a chave usada — atribuir volume a uma chave específica exigiria
 * uma coluna nova na trilha append-only. O endpoint responde a mesma pergunta
 * de produto ("o que estão usando de verdade?") com o dado que já existe.
 */
final class TopEndpointsChart extends BaseCompositionWidget
{
    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 2, 'xl' => 5];

    protected string $chartType = 'bar';

    /**
     * Quantas barras o ranking mostra.
     */
    private const TOPO = 5;

    public function getHeading(): string
    {
        return __('admin.dashboards.growth.chart_endpoints_heading');
    }

    protected function emptyIcon(): string|BackedEnum
    {
        return Heroicon::OutlinedListBullet;
    }

    /**
     * @return list<ChartSlice>
     */
    protected function chartSlices(Period $period): array
    {
        $contagem = [];

        RequestLog::query()
            ->where('created_at', '>=', $period->start())
            ->get(['method', 'endpoint'])
            ->each(function (RequestLog $log) use (&$contagem): void {
                $chave = $log->method.' /'.ltrim((string) $log->endpoint, '/');
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            });

        arsort($contagem);

        $fatias = [];

        foreach (array_slice($contagem, 0, self::TOPO, true) as $endpoint => $total) {
            $fatias[] = ChartSlice::make(Str::limit($endpoint, 32), (float) $total);
        }

        return $fatias;
    }
}
