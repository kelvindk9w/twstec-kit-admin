<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Growth;

use BackedEnum;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Admin\Resources\Projects\ProjectResource;
use Twstec\Kit\Admin\Widgets\Support\BaseLatestRecordsWidget;
use Twstec\Kit\Admin\Widgets\Support\Period;

/**
 * Projetos recentes com o dono e o quanto cada um já rende em chaves de API —
 * a leitura de "quem está de fato usando a plataforma".
 *
 * As contagens vêm por `withCount` (uma consulta, não N+1) e a coluna do dono
 * por eager loading.
 */
final class RecentProjectsTable extends BaseLatestRecordsWidget
{
    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 2, 'xl' => 8];

    /**
     * @return Builder<Project>
     */
    protected function latestQuery(Period $period): Builder
    {
        return Project::query()->with('account.owner')->withCount('apiKeys');
    }

    protected function latestHeading(): string
    {
        return __('admin.dashboards.growth.recent_projects');
    }

    protected function latestIcon(): string|BackedEnum
    {
        return Heroicon::OutlinedFolder;
    }

    protected function latestUrl(): ?string
    {
        return ProjectResource::getUrl();
    }

    /**
     * @return array<TextColumn>
     */
    protected function latestColumns(): array
    {
        return [
            TextColumn::make('name')
                ->label(__('admin.dashboards.growth.project_name'))
                ->weight(FontWeight::SemiBold)
                ->limit(30),

            TextColumn::make('account.owner.email')
                ->label(__('admin.dashboards.growth.project_owner'))
                ->color('gray')
                ->limit(28),

            TextColumn::make('api_keys_count')
                ->label(__('admin.dashboards.growth.project_keys'))
                ->badge()
                ->color('gray')
                ->alignEnd(),

            $this->whenColumn(),
        ];
    }
}
