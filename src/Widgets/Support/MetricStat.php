<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use BackedEnum;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Um card de estatística "premium" a partir de uma Metric: valor grande
 * formatado, sparkline da própria série, seta + Δ contra o período anterior e
 * cor de status com significado.
 *
 * É o componente que faz um widget novo nascer em três linhas:
 *
 *     MetricStat::make(__('admin.dashboards.overview.users'), $metrica)
 *         ->icon(Heroicon::OutlinedUsers)
 *         ->toStat()
 *
 * Convenções embutidas (para não repetir decisão de design card a card):
 * - sem base de comparação (período anterior zerado) o card NÃO inventa
 *   "+100%": diz "sem base de comparação", em cinza;
 * - métricas em que SUBIR É RUIM (taxa de erro, latência) chamam
 *   `->inverted()` e as cores se invertem — a paleta é sempre a StatusPalette;
 * - a classe `fi-dash-stat` marca o card para o CSS (tabular-nums) e para a
 *   animação de contagem (ver resources/views/dashboard-motion.blade.php do pacote).
 */
final class MetricStat
{
    private string|BackedEnum|null $icon = null;

    private MetricFormat $format = MetricFormat::Integer;

    private bool $inverted = false;

    private bool $deltaInPoints = false;

    private ?string $url = null;

    private ?string $hint = null;

    private ?float $headline = null;

    private function __construct(
        private readonly string $label,
        private readonly Metric $metric,
    ) {}

    public static function make(string $label, Metric $metric): self
    {
        return new self($label, $metric);
    }

    public function icon(string|BackedEnum|null $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function format(MetricFormat $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Subir é RUIM (taxa de erro, latência, submissões bloqueadas).
     */
    public function inverted(bool $inverted = true): self
    {
        $this->inverted = $inverted;

        return $this;
    }

    /**
     * Δ em PONTOS em vez de porcentagem — o certo quando a própria métrica já
     * é uma porcentagem (variar 50% sobre uma taxa de 2% não diz nada; "+1
     * ponto" diz).
     */
    public function deltaInPoints(bool $emPontos = true): self
    {
        $this->deltaInPoints = $emPontos;

        return $this;
    }

    /**
     * Texto curto acrescentado depois do Δ (o que o número significa).
     */
    public function hint(?string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    /**
     * Substitui o número grande do card, mantendo Δ e sparkline vindos da
     * métrica. É o padrão "total acumulado + crescimento do período": o card
     * mostra 1.248 usuários e a seta compara os NOVOS de cada janela.
     */
    public function headline(float $value): self
    {
        $this->headline = $value;

        return $this;
    }

    public function url(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function toStat(): Stat
    {
        $variacao = $this->deltaInPoints ? $this->metric->deltaAbsolute() : $this->metric->delta();
        $paleta = $this->palette($variacao);

        $stat = Stat::make($this->label, $this->format->display($this->headline ?? $this->metric->current()))
            ->description($this->description($variacao))
            ->descriptionIcon($this->descriptionIcon($variacao), IconPosition::Before)
            ->descriptionColor($paleta->filamentColor())
            ->color($paleta->filamentColor())
            ->extraAttributes(['class' => 'fi-dash-stat']);

        if ($this->icon !== null) {
            $stat = $stat->icon($this->icon);
        }

        $serie = $this->metric->series();

        // Sparkline só quando a série tem relevo: uma reta no zero é ruído
        // visual, não informação.
        if (count($serie) > 1 && max($serie) > 0.0) {
            $stat = $stat->chart($serie)->chartColor($paleta->filamentColor());
        }

        if ($this->url !== null) {
            $stat = $stat->url($this->url);
        }

        return $stat;
    }

    private function palette(?float $variacao): StatusPalette
    {
        if ($variacao === null || abs($variacao) < 0.05) {
            return StatusPalette::Neutral;
        }

        $subiu = $variacao > 0;

        return ($subiu xor $this->inverted) ? StatusPalette::Success : StatusPalette::Danger;
    }

    private function descriptionIcon(?float $variacao): string|BackedEnum
    {
        if ($variacao === null || abs($variacao) < 0.05) {
            return Heroicon::OutlinedMinusSmall;
        }

        return $variacao > 0 ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedArrowTrendingDown;
    }

    private function description(?float $variacao): string
    {
        $texto = $variacao === null
            ? __('admin.dashboards.common.no_baseline')
            : __('admin.dashboards.common.vs_previous', ['delta' => $this->deltaText($variacao)]);

        return $this->hint === null ? $texto : $texto.' · '.$this->hint;
    }

    private function deltaText(float $variacao): string
    {
        $sinal = $variacao > 0 ? '+' : ($variacao < 0 ? '−' : '');
        $absoluto = abs($variacao);
        $locale = app()->getLocale();

        if ($this->deltaInPoints) {
            return $sinal.Number::format($absoluto, precision: 1, locale: $locale).' '
                .trans_choice('admin.dashboards.common.points', $absoluto);
        }

        return $sinal.Number::format($absoluto, precision: 1, locale: $locale).'%';
    }
}
