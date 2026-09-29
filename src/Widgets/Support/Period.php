<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Janela de tempo de um dashboard (7/30/90 dias) e a janela ANTERIOR de
 * mesmo tamanho, que é o que dá sentido ao "Δ% vs. período anterior".
 *
 * O período é UM só por página: o seletor vive no filtro central da variante
 * (HasFiltersForm) e chega a todos os widgets por `$this->pageFilters`. Cada
 * widget pergunta `Period::fromFilters(...)` e nunca inventa a sua própria
 * janela — é isso que impede um dashboard de mostrar "30 dias" no gráfico e
 * "7 dias" no card ao lado.
 *
 * Convenção das bordas (fechada no início, aberta no fim):
 *   atual    = [start, end)          — `dias` dias terminando HOJE (inclusive)
 *   anterior = [previousStart, start)
 */
final readonly class Period
{
    private function __construct(public int $days) {}

    /**
     * Período a partir do valor do filtro da página. Valor ausente ou fora
     * das opções cai no padrão da config (nunca estoura).
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromFilters(?array $filters): self
    {
        $valor = $filters['period'] ?? null;

        return self::days(is_numeric($valor) ? (int) $valor : self::defaultDays());
    }

    public static function days(int $days): self
    {
        return new self(in_array($days, self::options(), true) ? $days : self::defaultDays());
    }

    /**
     * Opções do seletor (config/dashboards.php).
     *
     * @return list<int>
     */
    public static function options(): array
    {
        /** @var list<int> $opcoes */
        $opcoes = array_map('intval', (array) config('dashboards.periods', [7, 30, 90]));

        return $opcoes === [] ? [7, 30, 90] : $opcoes;
    }

    public static function defaultDays(): int
    {
        $padrao = (int) config('dashboards.period', 30);

        return in_array($padrao, self::options(), true) ? $padrao : (self::options()[0]);
    }

    /**
     * Primeiro instante da janela atual (início do dia).
     */
    public function start(): CarbonImmutable
    {
        return CarbonImmutable::instance(Carbon::now())->startOfDay()->subDays($this->days - 1);
    }

    /**
     * Fim (aberto) da janela atual: o instante de agora.
     */
    public function end(): CarbonImmutable
    {
        return CarbonImmutable::instance(Carbon::now());
    }

    public function previousStart(): CarbonImmutable
    {
        return $this->start()->subDays($this->days);
    }

    /**
     * Fim (aberto) da janela anterior = início da atual.
     */
    public function previousEnd(): CarbonImmutable
    {
        return $this->start();
    }

    /**
     * Todas as datas da janela ATUAL (Y-m-d), da mais antiga à de hoje. É o
     * esqueleto das séries: dia sem dado vira zero explícito, não um buraco.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        return $this->datesBetween($this->start());
    }

    /**
     * @return list<string>
     */
    public function previousDates(): array
    {
        return $this->datesBetween($this->previousStart());
    }

    /**
     * Rótulos curtos do eixo X (dd/mm).
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return array_map(
            fn (string $data): string => CarbonImmutable::parse($data)->format('d/m'),
            $this->dates(),
        );
    }

    public function label(): string
    {
        return __('admin.dashboards.filters.period_option', ['days' => $this->days]);
    }

    /**
     * @return list<string>
     */
    private function datesBetween(CarbonImmutable $inicio): array
    {
        $datas = [];

        for ($i = 0; $i < $this->days; $i++) {
            $datas[] = $inicio->addDays($i)->toDateString();
        }

        return $datas;
    }
}
