<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Compat;

/**
 * Nomes antigos (1.x) → nomes novos das classes do /admin, por UMA versão
 * (2.x); saem na 3.0.
 *
 * Na 1.x o painel morava no aplicativo, em App\Filament\… (e o comando
 * `user:make-admin` em App\Console\Commands, antes em App\Core\Auth\Console);
 * na 2.0 ele é deste pacote, em Twstec\Kit\Admin\…. A lista é FECHADA: só as
 * classes que existiam com o nome antigo — as peças novas do pacote (plugin,
 * provider, middleware de acesso…) nunca tiveram nome antigo, e uma tela que o
 * aplicativo escreve em App\Filament\ continua sendo dele.
 *
 * Quem usa:
 * - src/Compat/legacy-aliases.php — o nome antigo resolve para a classe nova
 *   (apelido preguiçoso);
 * - o Livewire, quando um snapshot aberto no navegador durante o deploy chega
 *   com o nome antigo do componente (o nome é a classe; o apelido a resolve);
 * - Support\LegacySessionState — o estado de tabela e de visualização que o
 *   Filament e o painel guardam NA SESSÃO pelo nome da classe.
 */
final class LegacyNames
{
    /**
     * @var array<string, class-string>
     */
    public const MAP = [
        'App\\Core\\Auth\\Console\\MakeAdminUser' => 'Twstec\\Kit\\Admin\\Console\\MakeAdminUser',
        'App\\Console\\Commands\\MakeAdminUser' => 'Twstec\\Kit\\Admin\\Console\\MakeAdminUser',
        'App\\Filament\\Auth\\EmailCodeAuthentication' => 'Twstec\\Kit\\Admin\\Auth\\EmailCodeAuthentication',
        'App\\Filament\\Dashboards\\BaseDashboard' => 'Twstec\\Kit\\Admin\\Dashboards\\BaseDashboard',
        'App\\Filament\\Dashboards\\DashboardRegistry' => 'Twstec\\Kit\\Admin\\Dashboards\\DashboardRegistry',
        'App\\Filament\\Dashboards\\GrowthDashboard' => 'Twstec\\Kit\\Admin\\Dashboards\\GrowthDashboard',
        'App\\Filament\\Dashboards\\OverviewDashboard' => 'Twstec\\Kit\\Admin\\Dashboards\\OverviewDashboard',
        'App\\Filament\\Pages\\Auth\\Login' => 'Twstec\\Kit\\Admin\\Pages\\Auth\\Login',
        'App\\Filament\\Pages\\Profile' => 'Twstec\\Kit\\Admin\\Pages\\Profile',
        'App\\Filament\\Pages\\Settings' => 'Twstec\\Kit\\Admin\\Pages\\Settings',
        'App\\Filament\\Resources\\ApiKeys\\ApiKeyResource' => 'Twstec\\Kit\\Admin\\Resources\\ApiKeys\\ApiKeyResource',
        'App\\Filament\\Resources\\ApiKeys\\Pages\\ListApiKeys' => 'Twstec\\Kit\\Admin\\Resources\\ApiKeys\\Pages\\ListApiKeys',
        'App\\Filament\\Resources\\AuditEvents\\AuditEventResource' => 'Twstec\\Kit\\Admin\\Resources\\AuditEvents\\AuditEventResource',
        'App\\Filament\\Resources\\AuditEvents\\Pages\\ListAuditEvents' => 'Twstec\\Kit\\Admin\\Resources\\AuditEvents\\Pages\\ListAuditEvents',
        'App\\Filament\\Resources\\AuditEvents\\Pages\\ViewAuditEvent' => 'Twstec\\Kit\\Admin\\Resources\\AuditEvents\\Pages\\ViewAuditEvent',
        'App\\Filament\\Resources\\Projects\\Pages\\ListProjects' => 'Twstec\\Kit\\Admin\\Resources\\Projects\\Pages\\ListProjects',
        'App\\Filament\\Resources\\Projects\\ProjectResource' => 'Twstec\\Kit\\Admin\\Resources\\Projects\\ProjectResource',
        'App\\Filament\\Resources\\RequestLogs\\Pages\\ListRequestLogs' => 'Twstec\\Kit\\Admin\\Resources\\RequestLogs\\Pages\\ListRequestLogs',
        'App\\Filament\\Resources\\RequestLogs\\Pages\\ViewRequestLog' => 'Twstec\\Kit\\Admin\\Resources\\RequestLogs\\Pages\\ViewRequestLog',
        'App\\Filament\\Resources\\RequestLogs\\RequestLogResource' => 'Twstec\\Kit\\Admin\\Resources\\RequestLogs\\RequestLogResource',
        'App\\Filament\\Resources\\Uploads\\Pages\\ListUploads' => 'Twstec\\Kit\\Admin\\Resources\\Uploads\\Pages\\ListUploads',
        'App\\Filament\\Resources\\Uploads\\UploadResource' => 'Twstec\\Kit\\Admin\\Resources\\Uploads\\UploadResource',
        'App\\Filament\\Resources\\Users\\Pages\\CreateUser' => 'Twstec\\Kit\\Admin\\Resources\\Users\\Pages\\CreateUser',
        'App\\Filament\\Resources\\Users\\Pages\\EditUser' => 'Twstec\\Kit\\Admin\\Resources\\Users\\Pages\\EditUser',
        'App\\Filament\\Resources\\Users\\Pages\\ListUsers' => 'Twstec\\Kit\\Admin\\Resources\\Users\\Pages\\ListUsers',
        'App\\Filament\\Resources\\Users\\Pages\\ViewUser' => 'Twstec\\Kit\\Admin\\Resources\\Users\\Pages\\ViewUser',
        'App\\Filament\\Resources\\Users\\Support\\MarkEmailVerifiedAction' => 'Twstec\\Kit\\Admin\\Resources\\Users\\Support\\MarkEmailVerifiedAction',
        'App\\Filament\\Resources\\Users\\Support\\UserAdminGuard' => 'Twstec\\Kit\\Admin\\Resources\\Users\\Support\\UserAdminGuard',
        'App\\Filament\\Resources\\Users\\UserResource' => 'Twstec\\Kit\\Admin\\Resources\\Users\\UserResource',
        'App\\Filament\\Support\\AdminAudit' => 'Twstec\\Kit\\Admin\\Support\\AdminAudit',
        'App\\Filament\\Support\\AdminColumns' => 'Twstec\\Kit\\Admin\\Support\\AdminColumns',
        'App\\Filament\\Support\\AttackLabel' => 'Twstec\\Kit\\Admin\\Support\\AttackLabel',
        'App\\Filament\\Support\\AvatarUpload' => 'Twstec\\Kit\\Admin\\Support\\AvatarUpload',
        'App\\Filament\\Support\\BaseListRecords' => 'Twstec\\Kit\\Admin\\Support\\BaseListRecords',
        'App\\Filament\\Support\\BaseResource' => 'Twstec\\Kit\\Admin\\Support\\BaseResource',
        'App\\Filament\\Support\\CardActions' => 'Twstec\\Kit\\Admin\\Support\\CardActions',
        'App\\Filament\\Support\\InitialsAvatarProvider' => 'Twstec\\Kit\\Admin\\Support\\InitialsAvatarProvider',
        'App\\Filament\\Support\\ViewMode' => 'Twstec\\Kit\\Admin\\Support\\ViewMode',
        'App\\Filament\\Support\\ViewModeToggle' => 'Twstec\\Kit\\Admin\\Support\\ViewModeToggle',
        'App\\Filament\\Widgets\\Growth\\GrowthStats' => 'Twstec\\Kit\\Admin\\Widgets\\Growth\\GrowthStats',
        'App\\Filament\\Widgets\\Growth\\RecentProjectsTable' => 'Twstec\\Kit\\Admin\\Widgets\\Growth\\RecentProjectsTable',
        'App\\Filament\\Widgets\\Growth\\RequestsGoalProgress' => 'Twstec\\Kit\\Admin\\Widgets\\Growth\\RequestsGoalProgress',
        'App\\Filament\\Widgets\\Growth\\TopEndpointsChart' => 'Twstec\\Kit\\Admin\\Widgets\\Growth\\TopEndpointsChart',
        'App\\Filament\\Widgets\\Growth\\UsersGrowthChart' => 'Twstec\\Kit\\Admin\\Widgets\\Growth\\UsersGrowthChart',
        'App\\Filament\\Widgets\\Overview\\LatestUploads' => 'Twstec\\Kit\\Admin\\Widgets\\Overview\\LatestUploads',
        'App\\Filament\\Widgets\\Overview\\OverviewStats' => 'Twstec\\Kit\\Admin\\Widgets\\Overview\\OverviewStats',
        'App\\Filament\\Widgets\\Overview\\RequestsStatusChart' => 'Twstec\\Kit\\Admin\\Widgets\\Overview\\RequestsStatusChart',
        'App\\Filament\\Widgets\\Overview\\RequestsTrendChart' => 'Twstec\\Kit\\Admin\\Widgets\\Overview\\RequestsTrendChart',
        'App\\Filament\\Widgets\\Support\\BaseCompositionWidget' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\BaseCompositionWidget',
        'App\\Filament\\Widgets\\Support\\BaseLatestRecordsWidget' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\BaseLatestRecordsWidget',
        'App\\Filament\\Widgets\\Support\\BaseStatsWidget' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\BaseStatsWidget',
        'App\\Filament\\Widgets\\Support\\BaseTimeSeriesWidget' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\BaseTimeSeriesWidget',
        'App\\Filament\\Widgets\\Support\\ChartSeries' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\ChartSeries',
        'App\\Filament\\Widgets\\Support\\ChartSlice' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\ChartSlice',
        'App\\Filament\\Widgets\\Support\\InteractsWithDashboardPeriod' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\InteractsWithDashboardPeriod',
        'App\\Filament\\Widgets\\Support\\Metric' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\Metric',
        'App\\Filament\\Widgets\\Support\\MetricAggregate' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\MetricAggregate',
        'App\\Filament\\Widgets\\Support\\MetricFormat' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\MetricFormat',
        'App\\Filament\\Widgets\\Support\\MetricStat' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\MetricStat',
        'App\\Filament\\Widgets\\Support\\Period' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\Period',
        'App\\Filament\\Widgets\\Support\\StatusPalette' => 'Twstec\\Kit\\Admin\\Widgets\\Support\\StatusPalette',
    ];

    /**
     * O nome novo de um nome antigo (null se não é um nome antigo).
     */
    public static function current(string $old): ?string
    {
        return self::MAP[$old] ?? null;
    }

    /**
     * O nome que a classe tinha na 1.x no painel (App\Filament\…), ou null
     * se ela não existia lá.
     */
    public static function previous(string $current): ?string
    {
        foreach (self::MAP as $old => $new) {
            if ($new === $current && str_starts_with($old, 'App\\Filament\\')) {
                return $old;
            }
        }

        return null;
    }
}
