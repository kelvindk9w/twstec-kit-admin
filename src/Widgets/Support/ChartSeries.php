<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

/**
 * Uma série de um gráfico de série temporal: rótulo, valores (um por dia da
 * janela) e o papel de cor da StatusPalette. Existe para que o widget
 * concreto não precise conhecer o formato de dataset do Chart.js.
 */
final readonly class ChartSeries
{
    /**
     * @param  list<float>  $values
     */
    private function __construct(
        public string $label,
        public array $values,
        public StatusPalette $palette,
        public bool $filled,
        public bool $dashed,
    ) {}

    /**
     * @param  list<float>  $values
     */
    public static function make(
        string $label,
        array $values,
        StatusPalette $palette = StatusPalette::Neutral,
        bool $filled = true,
        bool $dashed = false,
    ): self {
        return new self($label, array_values($values), $palette, $filled, $dashed);
    }

    /**
     * Dataset no vocabulário do Chart.js.
     *
     * @return array<string, mixed>
     */
    public function toDataset(string $type): array
    {
        $dataset = [
            'label' => $this->label,
            'data' => $this->values,
            'borderColor' => $this->palette->stroke(),
            'backgroundColor' => $type === 'bar'
                ? $this->palette->fill(0.75)
                : ($this->filled ? $this->palette->fill() : 'transparent'),
            'borderWidth' => $type === 'bar' ? 0 : 2,
            'fill' => $type === 'bar' ? true : $this->filled,
        ];

        if ($type === 'bar') {
            $dataset['borderRadius'] = 4;
            $dataset['maxBarThickness'] = 18;

            return $dataset;
        }

        $dataset['tension'] = 0.35;
        $dataset['pointRadius'] = 0;
        $dataset['pointHoverRadius'] = 4;
        $dataset['pointHitRadius'] = 12;

        if ($this->dashed) {
            $dataset['borderDash'] = [4, 4];
        }

        return $dataset;
    }
}
