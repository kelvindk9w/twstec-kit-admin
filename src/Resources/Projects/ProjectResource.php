<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Projects;

use BackedEnum;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectStatus;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Admin\Resources\Projects\Pages\ListProjects;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\BaseResource;

/**
 * Projetos — visão global (super admin). Somente leitura: projetos
 * são gerenciados pelo próprio usuário no painel.
 */
final class ProjectResource extends BaseResource
{
    protected static ?string $model = Project::class;

    protected static string $translationKey = 'admin.projects';

    protected static ?string $navigationGroupKey = 'admin.nav.group_management';

    // Posição no grupo "Gestão", de 10 em 10: a ordem do menu não pode
    // depender da ordem em que os resources são registrados (extensões, como
    // a demonstração do kit, registram os seus por outro caminho).
    protected static ?int $navigationSort = 30;

    // Navegação do /admin: TODO resource tem ícone (crítica de design #6 —
    // metade da nav aparecia como bolinha sem ícone).
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function tableColumns(): array
    {
        return [
            AdminColumns::publicCode(),
            TextColumn::make('name')
                ->label(__('panel.common.name'))
                ->searchable(),
            AdminColumns::account(),
            AdminColumns::accountOwner(__('admin.projects.owner')),
            AdminColumns::creator(),
            self::linkedKeysColumn(),
            self::statusColumn(),
            AdminColumns::dateTime('created_at', __('panel.common.created_at')),
        ];
    }

    public static function cardComponents(): array
    {
        return [
            Stack::make([
                Split::make([
                    TextColumn::make('name')
                        ->label(__('panel.common.name'))
                        ->weight(FontWeight::SemiBold)
                        ->size(TextSize::Large)
                        ->searchable(),
                    self::statusColumn()->grow(false),
                ]),
                AdminColumns::accountOwner(__('admin.projects.owner'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->color('gray')
                    ->size(TextSize::Small),
                Split::make([
                    self::linkedKeysColumn(),
                    AdminColumns::publicCode()
                        ->size(TextSize::Small)
                        ->color('gray')
                        ->grow(false),
                ]),
            ])->space(2),
        ];
    }

    private static function linkedKeysColumn(): TextColumn
    {
        return TextColumn::make('api_keys_count')
            ->label(__('admin.projects.linked_keys'))
            ->counts('apiKeys')
            ->badge()
            ->color('gray');
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('panel.common.status'))
            ->badge()
            ->formatStateUsing(fn (ProjectStatus $state): string => match ($state) {
                ProjectStatus::Active => __('admin.projects.status_active'),
                ProjectStatus::Archived => __('admin.projects.status_archived'),
            })
            ->color(fn (ProjectStatus $state): string => $state === ProjectStatus::Active ? 'success' : 'gray');
    }

    protected static function tableExtras(Table $table): Table
    {
        return $table
            ->filters([
                AdminColumns::accountFilter(),
                SelectFilter::make('status')
                    ->label(__('panel.common.status'))
                    ->options([
                        ProjectStatus::Active->value => __('admin.projects.status_active'),
                        ProjectStatus::Archived->value => __('admin.projects.status_archived'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
        ];
    }
}
