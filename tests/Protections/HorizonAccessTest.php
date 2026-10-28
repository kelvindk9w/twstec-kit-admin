<?php

declare(strict_types=1);

use Twstec\Kit\Admin\Access\HorizonAccess;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Enums\UserStatus;

// =============================================================================
// Dashboard de filas (/horizon): entrar no /admin não basta — o critério do
// gate `viewHorizon` do aplicativo pede a permissão `horizon.access` do papel.
// O Horizon mostra payload de job (dado pessoal) e refaz/apaga job: de
// fábrica, só o dono e a operação.
// =============================================================================

it('de fábrica, só o dono e a operação abrem o /horizon — suporte, auditoria e admin sem papel não', function (): void {
    $comPapel = fn (?string $role, array $attributes = []): User => User::fixture(['is_admin' => true, 'admin_role' => $role, ...$attributes]);

    expect(HorizonAccess::allows($comPapel('owner')))->toBeTrue()
        ->and(HorizonAccess::allows($comPapel('operations')))->toBeTrue()
        ->and(HorizonAccess::allows($comPapel('support')))->toBeFalse()
        // `*.view` não abre: o dashboard não tem modo só leitura.
        ->and(HorizonAccess::allows($comPapel('auditor')))->toBeFalse()
        ->and(HorizonAccess::allows($comPapel(null)))->toBeFalse()
        // Conta bloqueada e quem não é admin continuam de fora.
        ->and(HorizonAccess::allows($comPapel('owner', ['status' => UserStatus::Blocked])))->toBeFalse()
        ->and(HorizonAccess::allows(User::fixture(['admin_role' => 'owner'])))->toBeFalse()
        ->and(HorizonAccess::allows(null))->toBeFalse();
});

it('a permissão é do papel, como qualquer outra: um papel do projeto pode recebê-la', function (): void {
    config()->set('admin.authorization.roles.filas', ['horizon.access']);

    expect(HorizonAccess::allows(User::fixture(['is_admin' => true, 'admin_role' => 'filas'])))->toBeTrue()
        ->and(HorizonAccess::PERMISSION)->toBe('horizon.access');
});
