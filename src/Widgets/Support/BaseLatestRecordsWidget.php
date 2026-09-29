<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Admin\Support\AdminColumns;
use Twstec\Kit\Admin\Support\Concerns\MigratesLegacyTableState;

/**
 * Base das tabelas de "últimos N registros" dos dashboards.
 *
 * Todo tema de admin premium tem essa peça: cinco a oito linhas recentes, sem
 * paginação, com um link "ver tudo" para a listagem completa. O widget
 * concreto declara a consulta, as colunas, o título e o destino do "ver
 * tudo"; daqui vêm o limite (config `dashboards.latest_records`), a ordenação
 * por data, a ausência de paginação/busca (é um resumo, não uma ferramenta de
 * triagem) e o ESTADO VAZIO ilustrado e traduzido.
 */
abstract class BaseLatestRecordsWidget extends TableWidget
{
    use InteractsWithDashboardPeriod;
    use MigratesLegacyTableState;

    protected ?string $pollingInterval = null;

    /**
     * Consulta base (sem limite nem ordenação — a base cuida disso).
     *
     * @return Builder<*>
     */
    abstract protected function latestQuery(Period $period): Builder;

    /**
     * @return array<Column>
     */
    abstract protected function latestColumns(): array;

    abstract protected function latestHeading(): string;

    /**
     * Ícone do estado vazio: o assunto da tabela.
     */
    abstract protected function latestIcon(): string|BackedEnum;

    /**
     * URL da listagem completa ("ver tudo"). `null` = sem ação no cabeçalho.
     */
    protected function latestUrl(): ?string
    {
        return null;
    }

    /**
     * Coluna "Quando" no formato CURTO do dashboard.
     *
     * A listagem completa mostra data e hora; um resumo de seis linhas dentro
     * de meia largura de tela, não — a hora empurrava a coluna para fora do
     * card. Quem precisa do minuto clica em "ver tudo".
     */
    protected function whenColumn(): TextColumn
    {
        return AdminColumns::dateTime('created_at', __('admin.dashboards.common.when'), 'd/m/Y')
            ->sortable(false)
            ->color('gray');
    }

    protected function latestDateColumn(): string
    {
        return 'created_at';
    }

    protected function latestLimit(): int
    {
        return max(3, (int) config('dashboards.latest_records', 6));
    }

    public function table(Table $table): Table
    {
        $consulta = $this->latestQuery($this->period())
            ->orderByDesc($this->latestDateColumn())
            ->limit($this->latestLimit());

        return $table
            ->query(fn (): Builder => $consulta)
            ->heading($this->latestHeading())
            ->description(__('admin.dashboards.common.latest_description', ['count' => $this->latestLimit()]))
            ->columns($this->latestColumns())
            ->paginated(false)
            ->headerActions(array_filter([
                $this->latestUrl() === null ? null : Action::make('seeAll')
                    ->label(__('admin.dashboards.common.see_all'))
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->iconPosition('after')
                    ->color('gray')
                    ->link()
                    ->url($this->latestUrl()),
            ]))
            ->emptyStateIcon($this->latestIcon())
            ->emptyStateHeading(__('admin.dashboards.common.empty_table_heading'))
            ->emptyStateDescription(__('admin.dashboards.common.empty_table_description'));
    }
}
