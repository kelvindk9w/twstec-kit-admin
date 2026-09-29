<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Concerns;

use Filament\Panel;
use Twstec\Kit\Admin\Access\AdminAccess;

/**
 * `canAccessPanel` do contrato FilamentUser, com o critério do pacote
 * (Access\AdminAccess: `is_admin` + conta ativa).
 *
 * O model de usuário do aplicativo implementa Filament\Models\Contracts\FilamentUser
 * e usa esta trait. Mesmo sem ela, o pacote confere o mesmo critério no
 * middleware do painel — a trait existe para o Filament e o pacote
 * responderem igual (menu, redirecionamentos, 403).
 */
trait AccessesAdminPanel
{
    public function canAccessPanel(Panel $panel): bool
    {
        return AdminAccess::allows($this);
    }
}
