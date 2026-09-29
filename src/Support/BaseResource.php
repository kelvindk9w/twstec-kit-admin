<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\Layout\Component as ColumnLayoutComponent;
use Filament\Tables\Table;

/**
 * Base de TODO resource do super admin (/admin).
 *
 * O painel tinha sete resources repetindo as mesmas dez linhas: rota por
 * uuid, três métodos de rótulo, grupo de navegação, ordenação padrão,
 * paginação, estado vazio, coluna de código público e coluna de data no
 * fuso da plataforma. Copiar isso a cada tela nova é como o padrão se
 * perde — basta um esquecimento para a listagem nascer sem estado vazio
 * traduzido ou com data no fuso do banco.
 *
 * Aqui o comportamento comum é herdado e o resource declara SÓ o que é
 * dele: o model, o prefixo de tradução, o ícone/grupo de navegação, as
 * colunas da tabela, (opcionalmente) o layout dos cards e um hook
 * `tableExtras()` para filtros e ações.
 *
 * ALTERNADOR TABELA/CARDS: quando o resource implementa `cardComponents()`,
 * a listagem ganha um botão SÓ ÍCONE na barra da tabela, ao lado do ícone
 * de filtros e da busca (ViewModeToggle), que troca entre a tabela clássica
 * e a grade de cards. A escolha persiste por usuário e por recurso — ver
 * ViewMode.
 *
 * AÇÕES NO MODO CARDS: no card, as ações de registro viram botões de ícone
 * com cor semântica e nome no hover (CardActions), distribuídas em partes
 * iguais no rodapé do card. Na tabela nada muda. O resource NÃO precisa
 * saber disso: a conversão é feita aqui, em `modifyUngroupedRecordActionsUsing`,
 * antes de o hook `tableExtras()` declarar as ações.
 *
 * Como criar uma tela nova está documentado em docs/admin-e-dashboards.md.
 */
abstract class BaseResource extends Resource
{
    /**
     * O id interno NUNCA vai para a URL: impede enumeração de registros.
     */
    protected static ?string $recordRouteKeyName = 'uuid';

    /**
     * Prefixo das chaves de tradução do recurso (ex.: 'admin.users' —
     * espera `.label` e `.plural` em lang/{pt_BR,en,es}/admin.php).
     */
    protected static string $translationKey = '';

    /**
     * Chave de tradução do grupo de navegação (ex.: 'admin.nav.group_management').
     */
    protected static ?string $navigationGroupKey = null;

    /**
     * Ordenação padrão da listagem. `null` na coluna = o resource ordena
     * por conta própria (ex.: Submissões, que sobem os bloqueados).
     */
    protected static ?string $defaultSortColumn = 'created_at';

    protected static string $defaultSortDirection = 'desc';

    /**
     * Opções de paginação (a primeira vira o padrão).
     *
     * @var list<int|string>
     */
    protected static array $paginationOptions = [10, 25, 50];

    /**
     * Colunas da listagem CLÁSSICA (tabela).
     *
     * @return array<Column|ColumnLayoutComponent|ColumnGroup>
     */
    abstract public static function tableColumns(): array;

    /**
     * Layout do modo CARDS (Stack/Split/Grid de colunas). Array vazio =
     * recurso sem alternador — a listagem fica só em tabela.
     *
     * @return array<Column|ColumnLayoutComponent>
     */
    public static function cardComponents(): array
    {
        return [];
    }

    /**
     * Grade dos cards por breakpoint (Table::contentGrid).
     *
     * @return array<string, int|null>
     */
    public static function cardGrid(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 3];
    }

    public static function hasCardView(): bool
    {
        return static::cardComponents() !== [];
    }

    /**
     * Hook do resource: filtros, ações, query e o que mais for específico.
     */
    protected static function tableExtras(Table $table): Table
    {
        return $table;
    }

    public static function getModelLabel(): string
    {
        return __(static::$translationKey.'.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$translationKey.'.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$navigationGroupKey === null ? null : __(static::$navigationGroupKey);
    }

    /**
     * Montagem final da tabela: defaults comuns + o hook do resource.
     * O resource NÃO sobrescreve este método (sobrescreve os hooks).
     */
    public static function table(Table $table): Table
    {
        $isGrid = static::hasCardView() && ViewMode::for(static::class)->isGrid();

        $table = $table
            ->columns($isGrid ? static::cardComponents() : static::tableColumns())
            ->paginationPageOptions(static::$paginationOptions)
            ->defaultPaginationPageOption(static::$paginationOptions[0] ?? 10)
            // Estado vazio traduzido em toda listagem: uma tabela em branco
            // sem explicação parece defeito, não ausência de dados.
            ->emptyStateIcon(static::getNavigationIcon())
            ->emptyStateHeading(__('admin.common.empty_heading', ['records' => static::getPluralModelLabel()]))
            ->emptyStateDescription(__('admin.common.empty_description'));

        if (static::$defaultSortColumn !== null) {
            $table = $table->defaultSort(static::$defaultSortColumn, static::$defaultSortDirection);
        }

        if ($isGrid) {
            $table = $table
                ->contentGrid(static::cardGrid())
                ->recordActionsAlignment(Alignment::Center->value)
                // Precisa vir ANTES de tableExtras(): o Filament aplica este
                // modificador no momento em que `recordActions()` é chamado.
                ->modifyUngroupedRecordActionsUsing(
                    fn (Action $action) => CardActions::style($action),
                );
        }

        $table = static::tableExtras($table);

        // Depois do hook, e com `push`, para que um resource que zere as
        // ações da barra (`->toolbarActions([])`) não leve o alternador junto.
        if (static::hasCardView()) {
            $table = $table->pushToolbarActions([ViewModeToggle::make(static::class)]);
        }

        return $table;
    }
}
