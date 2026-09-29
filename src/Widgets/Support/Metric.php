<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use Closure;
use Illuminate\Support\Carbon;

/**
 * O motor por trás de TODO número dos dashboards: uma consulta + uma coluna
 * de data viram, de uma vez só, o valor do período, o valor do período
 * anterior, o Δ% entre os dois e a série diária do sparkline.
 *
 * Por que existe: sem isto, cada card do painel repetiria três consultas e
 * uma conta de porcentagem — e bastaria um esquecimento para um card comparar
 * 30 dias com 7. Aqui a comparação é sempre "mesma janela, deslocada para
 * trás" (ver Period), e o widget só diz o que quer contar.
 *
 * DECISÃO: o agrupamento por dia é feito em PHP, não em SQL — a mesma
 * decisão do RequestsChart original. A suíte roda em SQLite e a aplicação em
 * PostgreSQL, e funções de data divergem entre os dois; o volume das janelas
 * (no máximo 180 dias de tabelas do próprio painel) não paga um SQL por driver.
 */
final class Metric
{
    /**
     * Balde por dia (Y-m-d): quantos registros e a soma da coluna de valor.
     *
     * @var array<string, array{n: int, sum: float}>
     */
    private array $baldes = [];

    /**
     * Atalho de métricas já calculadas (ver fromValues): [atual, anterior, série].
     *
     * @var array{0: float, 1: float, 2: list<float>}|null
     */
    private ?array $pronta = null;

    private function __construct(
        private readonly Period $period,
        private readonly MetricAggregate $aggregate,
    ) {}

    /**
     * Quantos registros por dia (novos usuários, requisições, uploads...).
     *
     * @param  Closure(): (\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder)  $query
     */
    public static function count(Closure $query, Period $period, string $dateColumn = 'created_at'): self
    {
        return self::build($query, $period, $dateColumn, null, MetricAggregate::Count);
    }

    /**
     * Soma de uma coluna por dia (ex.: bytes armazenados).
     *
     * @param  Closure(): (\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder)  $query
     */
    public static function sum(Closure $query, string $valueColumn, Period $period, string $dateColumn = 'created_at'): self
    {
        return self::build($query, $period, $dateColumn, $valueColumn, MetricAggregate::Sum);
    }

    /**
     * Média de uma coluna por dia (ex.: latência em ms).
     *
     * @param  Closure(): (\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder)  $query
     */
    public static function average(Closure $query, string $valueColumn, Period $period, string $dateColumn = 'created_at'): self
    {
        return self::build($query, $period, $dateColumn, $valueColumn, MetricAggregate::Average);
    }

    /**
     * Métrica montada a partir de números já calculados — a saída de escape
     * para razões e proporções (taxa de erro = erros/total), onde somar dia a
     * dia não teria sentido.
     *
     * @param  list<float>  $series  Um ponto por dia da janela atual.
     */
    public static function fromValues(float $current, float $previous, array $series): self
    {
        $metrica = new self(Period::days(count($series) > 0 ? count($series) : Period::defaultDays()), MetricAggregate::Sum);

        $metrica->pronta = [$current, $previous, array_values($series)];

        return $metrica;
    }

    /**
     * Razão entre duas métricas, em porcentagem (taxa de erro, taxa de
     * conversão): o Δ passa a ter sentido comparando as DUAS janelas, e a
     * série diária é a razão dia a dia — não a razão das somas.
     */
    public static function ratio(self $numerator, self $denominator, float $scale = 100.0): self
    {
        $razao = static fn (float $topo, float $base): float => $base > 0.0 ? ($topo / $base) * $scale : 0.0;

        $serieBase = $denominator->series();
        $serie = [];

        foreach ($numerator->series() as $indice => $valor) {
            $serie[] = $razao($valor, $serieBase[$indice] ?? 0.0);
        }

        return self::fromValues(
            $razao($numerator->current(), $denominator->current()),
            $razao($numerator->previous(), $denominator->previous()),
            $serie,
        );
    }

    /**
     * Valor agregado da janela ATUAL.
     */
    public function current(): float
    {
        return $this->pronta[0] ?? $this->aggregateOf($this->period->dates());
    }

    /**
     * Valor agregado da janela ANTERIOR (mesmo tamanho, deslocada para trás).
     */
    public function previous(): float
    {
        return $this->pronta[1] ?? $this->aggregateOf($this->period->previousDates());
    }

    /**
     * Variação percentual contra o período anterior.
     *
     * `null` quando NÃO HÁ BASE DE COMPARAÇÃO (período anterior zerado):
     * "+100%" sobre zero é uma mentira de arredondamento, e o card diz
     * "sem base de comparação" em vez de inventar uma seta verde.
     */
    public function delta(): ?float
    {
        $anterior = $this->previous();

        if ($anterior === 0.0) {
            return null;
        }

        return (($this->current() - $anterior) / abs($anterior)) * 100;
    }

    /**
     * Diferença ABSOLUTA contra o período anterior (usada por métricas em %,
     * onde a variação relativa de uma porcentagem confunde: o certo é dizer
     * "+1,2 ponto").
     */
    public function deltaAbsolute(): float
    {
        return $this->current() - $this->previous();
    }

    /**
     * Série diária da janela atual — é ela que vira o sparkline do card.
     *
     * @return list<float>
     */
    public function series(): array
    {
        if ($this->pronta !== null) {
            return $this->pronta[2];
        }

        return array_map(fn (string $data): float => $this->valueOf($data), $this->period->dates());
    }

    /**
     * Série ACUMULADA: cada ponto soma tudo o que veio antes (crescimento de
     * base de usuários, por exemplo).
     *
     * @param  float  $base  Quanto já existia ANTES do primeiro dia da janela.
     * @return list<float>
     */
    public function cumulativeSeries(float $base = 0.0): array
    {
        $acumulado = $base;

        return array_map(function (float $valor) use (&$acumulado): float {
            $acumulado += $valor;

            return $acumulado;
        }, $this->series());
    }

    /**
     * @param  Closure(): (\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder)  $query
     */
    private static function build(
        Closure $query,
        Period $period,
        string $dateColumn,
        ?string $valueColumn,
        MetricAggregate $aggregate,
    ): self {
        $metrica = new self($period, $aggregate);

        $colunas = $valueColumn === null ? [$dateColumn] : [$dateColumn, $valueColumn];

        $linhas = $query()
            ->where($dateColumn, '>=', $period->previousStart())
            ->get($colunas);

        foreach ($linhas as $linha) {
            $bruto = is_array($linha) ? ($linha[$dateColumn] ?? null) : ($linha->{$dateColumn} ?? null);

            if ($bruto === null) {
                continue;
            }

            $dia = ($bruto instanceof \DateTimeInterface ? Carbon::instance($bruto) : Carbon::parse((string) $bruto))
                ->toDateString();

            $valor = 0.0;

            if ($valueColumn !== null) {
                $valorBruto = is_array($linha) ? ($linha[$valueColumn] ?? 0) : ($linha->{$valueColumn} ?? 0);
                $valor = is_numeric($valorBruto) ? (float) $valorBruto : 0.0;
            }

            $metrica->baldes[$dia] ??= ['n' => 0, 'sum' => 0.0];
            $metrica->baldes[$dia]['n']++;
            $metrica->baldes[$dia]['sum'] += $valor;
        }

        return $metrica;
    }

    /**
     * @param  list<string>  $datas
     */
    private function aggregateOf(array $datas): float
    {
        $n = 0;
        $soma = 0.0;

        foreach ($datas as $data) {
            $n += $this->baldes[$data]['n'] ?? 0;
            $soma += $this->baldes[$data]['sum'] ?? 0.0;
        }

        return match ($this->aggregate) {
            MetricAggregate::Count => (float) $n,
            MetricAggregate::Sum => $soma,
            MetricAggregate::Average => $n > 0 ? $soma / $n : 0.0,
        };
    }

    private function valueOf(string $data): float
    {
        $balde = $this->baldes[$data] ?? ['n' => 0, 'sum' => 0.0];

        return match ($this->aggregate) {
            MetricAggregate::Count => (float) $balde['n'],
            MetricAggregate::Sum => $balde['sum'],
            MetricAggregate::Average => $balde['n'] > 0 ? $balde['sum'] / $balde['n'] : 0.0,
        };
    }
}
