<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

/**
 * A ÚNICA paleta de status dos dashboards: cor de status só com propósito.
 *
 * Regra: verde/âmbar/vermelho só carregam SIGNIFICADO — sucesso, atenção,
 * falha. Todo o resto do painel é neutro. Sem um lugar só para isso, "erro de
 * API" no gráfico, "submissão bloqueada" na tabela e "meta não atingida" no
 * progresso acabariam com três vermelhos diferentes.
 *
 * Cada papel devolve duas coisas: o NOME da cor do Filament (Stat, badge,
 * botão) e o HEX que o Chart.js precisa (o Chart.js desenha em canvas, não
 * enxerga classe do Tailwind). Os hexes acompanham os tokens de
 * resources/css/theme.css.
 */
enum StatusPalette: string
{
    case Success = 'success';

    case Warning = 'warning';

    case Danger = 'danger';

    case Neutral = 'neutral';

    /**
     * Nome da cor no vocabulário do Filament (Stat::color(), badges).
     */
    public function filamentColor(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Warning => 'warning',
            self::Danger => 'danger',
            self::Neutral => 'gray',
        };
    }

    /**
     * Traço da série no Chart.js.
     */
    public function stroke(): string
    {
        return match ($this) {
            self::Success => '#16a34a',
            self::Warning => '#d97706',
            self::Danger => '#dc2626',
            self::Neutral => '#71717a',
        };
    }

    /**
     * Preenchimento (área sob a linha, fatia do doughnut, barra).
     */
    public function fill(float $opacity = 0.14): string
    {
        [$r, $g, $b] = $this->rgb();

        return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim(rtrim(number_format($opacity, 2, '.', ''), '0'), '.'));
    }

    /**
     * Sequência de cores para gráficos de COMPOSIÇÃO (doughnut/barras por
     * categoria), quando as fatias não têm significado de status: uma escala
     * neutra graduada, com os papéis de status reservados para quem os merece.
     *
     * A escala fica no MEIO do espectro de cinzas de propósito: o Chart.js
     * pinta em canvas e não sabe se o painel está claro ou escuro, então um
     * quase-preto (bonito no tema claro) sumiria no escuro — e vice-versa.
     * Estes seis tons têm contraste suficiente contra os dois fundos.
     *
     * @return list<string>
     */
    public static function categorical(): array
    {
        return ['#71717a', '#a1a1aa', '#52525b', '#d4d4d8', '#3f3f46', '#e4e4e7'];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(): array
    {
        $hex = ltrim($this->stroke(), '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
