<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

/**
 * Uma fatia de um gráfico de composição (doughnut ou barras por categoria).
 *
 * `palette` só é preenchida quando a fatia TEM significado de status (2xx
 * verde, 5xx vermelho); nos demais casos a cor vem da escala neutra graduada
 * da StatusPalette — cor sem significado é decoração, e decoração colorida é
 * o que faz um painel parecer template barato.
 */
final readonly class ChartSlice
{
    private function __construct(
        public string $label,
        public float $value,
        public ?StatusPalette $palette,
    ) {}

    public static function make(string $label, float $value, ?StatusPalette $palette = null): self
    {
        return new self($label, $value, $palette);
    }
}
