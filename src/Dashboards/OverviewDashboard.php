<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Dashboards;

use Twstec\Kit\Admin\Widgets\Overview\LatestUploads;
use Twstec\Kit\Admin\Widgets\Overview\OverviewStats;
use Twstec\Kit\Admin\Widgets\Overview\RequestsStatusChart;
use Twstec\Kit\Admin\Widgets\Overview\RequestsTrendChart;
use Twstec\Kit\Foundation\Kit;

/**
 * VARIANTE A — "Visão Geral" (Analytics).
 *
 * Público: quem abre o painel para saber, em dez segundos, se a plataforma
 * está saudável. É a variante padrão (responde em /admin) porque responde à
 * pergunta mais genérica: quanto temos, quanto entrou, o que quebrou.
 *
 * Grade (12 colunas no desktop):
 *   [ KPIs: usuários · requisições · chaves · projetos ............... 12 ]
 *   [ Requisições por dia (linha + erros) .. 8 ][ Status (doughnut) .. 4 ]
 *   [ (extensões: ex. últimas submissões) .. 6 ][ Últimos uploads .... 6 ]
 */
final class OverviewDashboard extends BaseDashboard
{
    public static function variant(): string
    {
        return 'overview';
    }

    /**
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        // Extensões instaladas podem acrescentar widgets (ver
        // DashboardRegistry::widgets) — a demonstração põe as últimas
        // submissões de formulário antes dos últimos uploads.
        return DashboardRegistry::widgets(self::variant(), [
            OverviewStats::class,
            RequestsTrendChart::class,
            RequestsStatusChart::class,
            // Uploads são do twstec/kit-uploads.
            ...(Kit::has('uploads') ? [LatestUploads::class] : []),
        ]);
    }
}
