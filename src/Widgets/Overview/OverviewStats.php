<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Overview;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Admin\Widgets\Support\BaseStatsWidget;
use Twstec\Kit\Admin\Widgets\Support\Metric;
use Twstec\Kit\Admin\Widgets\Support\MetricStat;
use Twstec\Kit\Admin\Widgets\Support\Period;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Os quatro números de abertura do painel: tamanho da base, movimento da API,
 * credenciais vivas e projetos. Cada card mostra o TOTAL acumulado e compara
 * o que ENTROU no período com o período anterior — é a diferença entre "temos
 * 1.240 usuários" e "estamos crescendo".
 *
 * Chaves e projetos são do twstec/kit-accounts: sem ele, o widget mostra só
 * pessoas e requisições.
 */
final class OverviewStats extends BaseStatsWidget
{
    /**
     * @return list<MetricStat|Stat>
     */
    protected function metrics(Period $period): array
    {
        return [
            MetricStat::make(
                __('admin.dashboards.overview.users'),
                Metric::count(fn () => UserModel::query(), $period),
            )
                ->headline((float) UserModel::query()->count())
                ->icon(Heroicon::OutlinedUsers)
                ->hint(__('admin.dashboards.overview.users_hint')),

            MetricStat::make(
                __('admin.dashboards.overview.requests'),
                Metric::count(fn () => RequestLog::query(), $period),
            )
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->hint(__('admin.dashboards.overview.requests_hint')),

            ...(Kit::has('accounts') ? $this->accountMetrics($period) : []),
        ];
    }

    /**
     * Chaves ativas e projetos (twstec/kit-accounts).
     *
     * @return list<MetricStat>
     */
    private function accountMetrics(Period $period): array
    {
        return [
            MetricStat::make(
                __('admin.dashboards.overview.api_keys'),
                Metric::count(fn () => ApiKey::query(), $period),
            )
                ->headline((float) ApiKey::query()->where('status', ApiKeyStatus::Active->value)->count())
                ->icon(Heroicon::OutlinedKey)
                ->hint(__('admin.dashboards.overview.api_keys_hint')),

            MetricStat::make(
                __('admin.dashboards.overview.projects'),
                Metric::count(fn () => Project::query(), $period),
            )
                ->headline((float) Project::query()->count())
                ->icon(Heroicon::OutlinedFolder)
                ->hint(__('admin.dashboards.overview.projects_hint')),
        ];
    }
}
