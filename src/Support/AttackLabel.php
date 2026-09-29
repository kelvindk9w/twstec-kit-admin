<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Rótulo traduzido de um tipo de ataque detectado (AttackDetector: `xss`,
 * `sqli`, `honeypot`…), para as telas do /admin que mostram tentativas.
 *
 * Tipo desconhecido (detector novo, registro antigo) cai num rótulo genérico
 * em vez de imprimir a chave de tradução crua na tela. As chaves continuam em
 * `admin.submissions.attack_*`, onde nasceram.
 */
final class AttackLabel
{
    public static function for(?string $type): string
    {
        $key = 'admin.submissions.attack_'.(string) $type;

        return $type !== null && Lang::has($key) ? __($key) : __('admin.submissions.attack_unknown');
    }
}
