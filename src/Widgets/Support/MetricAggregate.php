<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Support;

/**
 * Como os registros de um dia viram UM número na série de uma métrica.
 */
enum MetricAggregate
{
    /** Quantos registros caíram no dia. */
    case Count;

    /** Soma de uma coluna (ex.: bytes de upload). */
    case Sum;

    /** Média de uma coluna (ex.: duração em ms). Dia sem registro = 0. */
    case Average;
}
