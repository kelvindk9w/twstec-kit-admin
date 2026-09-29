<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Growth;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Admin\Widgets\Support\BaseStatsWidget;
use Twstec\Kit\Admin\Widgets\Support\Metric;
use Twstec\Kit\Admin\Widgets\Support\MetricFormat;
use Twstec\Kit\Admin\Widgets\Support\MetricStat;
use Twstec\Kit\Admin\Widgets\Support\Period;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Adoção e saúde da plataforma em quatro números: quem chegou, quanto quebrou,
 * quão rápido respondeu e quantas credenciais novas foram emitidas.
 *
 * Dois deles são INVERTIDOS (`->inverted()`): em taxa de erro e latência,
 * subir é ruim — e a cor tem de dizer isso sem legenda.
 *
 * As chaves novas são do twstec/kit-accounts: sem ele, o card não aparece.
 */
final class GrowthStats extends BaseStatsWidget
{
    /**
     * @return list<MetricStat|Stat>
     */
    protected function metrics(Period $period): array
    {
        $requisicoes = Metric::count(fn () => RequestLog::query(), $period);
        $erros = Metric::count(
            fn () => RequestLog::query()->where('http_status_response', '>=', 400),
            $period,
        );

        return [
            MetricStat::make(
                __('admin.dashboards.growth.new_users'),
                Metric::count(fn () => UserModel::query(), $period),
            )
                ->icon(Heroicon::OutlinedUserPlus)
                ->hint(__('admin.dashboards.growth.new_users_hint')),

            MetricStat::make(
                __('admin.dashboards.growth.error_rate'),
                Metric::ratio($erros, $requisicoes),
            )
                ->format(MetricFormat::Percent)
                ->deltaInPoints()
                ->inverted()
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->hint(__('admin.dashboards.growth.error_rate_hint')),

            MetricStat::make(
                __('admin.dashboards.growth.latency'),
                Metric::average(
                    fn () => RequestLog::query()->whereNotNull('duration_ms'),
                    'duration_ms',
                    $period,
                ),
            )
                ->format(MetricFormat::Milliseconds)
                ->inverted()
                ->icon(Heroicon::OutlinedBolt)
                ->hint(__('admin.dashboards.growth.latency_hint')),

            ...(Kit::has('accounts') ? [
                MetricStat::make(
                    __('admin.dashboards.growth.new_api_keys'),
                    Metric::count(fn () => ApiKey::query(), $period),
                )
                    ->icon(Heroicon::OutlinedKey)
                    ->hint(__('admin.dashboards.growth.new_api_keys_hint')),
            ] : []),
        ];
    }
}
