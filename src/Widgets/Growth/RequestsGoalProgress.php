<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Growth;

use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Twstec\Kit\Admin\Widgets\Support\InteractsWithDashboardPeriod;
use Twstec\Kit\Admin\Widgets\Support\MetricFormat;
use Twstec\Kit\Admin\Widgets\Support\StatusPalette;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Meta do MÊS de requisições — a "progress metric" que todo dashboard de SaaS
 * tem e que nenhum dado de domínio do kit fornece.
 *
 * A meta é config (`dashboards.goals.monthly_requests`, .env
 * DASHBOARD_GOAL_MONTHLY_REQUESTS) e não tabela, de propósito: meta é
 * parâmetro de operação, muda por decisão de gente, e criar uma tabela para
 * ela seria transformar uma configuração em domínio.
 *
 * O widget compara o realizado com o RITMO esperado do mês (quanto já
 * deveria ter acontecido a esta altura) — sem isso, "42% no dia 20" parece
 * ruim e é, mas "42% no dia 3" seria ótimo e pareceria péssimo.
 *
 * Este é também o exemplo de widget CUSTOM (Blade livre) do kit: quando o
 * formato não é card, gráfico nem tabela, a base certa é esta.
 */
final class RequestsGoalProgress extends Widget
{
    use InteractsWithDashboardPeriod;

    protected string $view = 'kit-admin::widgets.goal-progress';

    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 2, 'xl' => 4];

    protected ?string $pollingInterval = null;

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $inicioDoMes = Carbon::now()->startOfMonth();
        $meta = max(1, (int) config('dashboards.goals.monthly_requests', 1500));

        $realizado = RequestLog::query()->where('created_at', '>=', $inicioDoMes)->count();

        $diasNoMes = (int) Carbon::now()->daysInMonth;
        $diaAtual = (int) Carbon::now()->day;

        $progresso = min(100.0, ($realizado / $meta) * 100);
        $ritmoEsperado = ($diaAtual / $diasNoMes) * 100;

        $paleta = match (true) {
            $progresso >= $ritmoEsperado => StatusPalette::Success,
            $progresso >= $ritmoEsperado * 0.75 => StatusPalette::Warning,
            default => StatusPalette::Danger,
        };

        return [
            'heading' => __('admin.dashboards.growth.goal_heading'),
            'description' => __('admin.dashboards.growth.goal_description', [
                'month' => Carbon::now()->translatedFormat('F'),
            ]),
            'achieved' => MetricFormat::Integer->display((float) $realizado),
            'goal' => MetricFormat::Integer->display((float) $meta),
            'progress' => round($progresso, 1),
            'progressLabel' => MetricFormat::Percent->display($progresso),
            'pace' => round($ritmoEsperado, 1),
            'paceLabel' => __('admin.dashboards.growth.goal_pace', [
                'pace' => MetricFormat::Percent->display($ritmoEsperado),
            ]),
            'status' => $paleta->filamentColor(),
            'statusLabel' => match ($paleta) {
                StatusPalette::Success => __('admin.dashboards.growth.goal_on_track'),
                StatusPalette::Warning => __('admin.dashboards.growth.goal_at_risk'),
                default => __('admin.dashboards.growth.goal_behind'),
            },
            'stroke' => $paleta->stroke(),
        ];
    }
}
