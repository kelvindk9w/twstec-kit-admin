<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

use Illuminate\Support\Number;

/**
 * Como o número de uma métrica é ESCRITO no card. Formatar na borda (e nunca
 * no cálculo) é a mesma regra do dinheiro do kit: o widget guarda
 * float, a tela mostra texto no idioma do usuário.
 */
enum MetricFormat
{
    case Integer;

    /** Uma casa decimal + "%" (taxa de erro, conversão). */
    case Percent;

    /** Milissegundos (latência) — vira "s" acima de 1000. */
    case Milliseconds;

    /** Bytes — KB/MB/GB conforme a grandeza. */
    case Bytes;

    public function display(float $value): string
    {
        $locale = app()->getLocale();

        return match ($this) {
            self::Integer => (string) Number::format($value, precision: 0, locale: $locale),
            self::Percent => Number::format($value, precision: 1, locale: $locale).'%',
            self::Milliseconds => $value >= 1000
                ? Number::format($value / 1000, precision: 2, locale: $locale).' s'
                : Number::format($value, precision: 0, locale: $locale).' ms',
            self::Bytes => (string) Number::fileSize($value, precision: $value >= 1024 * 1024 ? 1 : 0),
        };
    }
}
