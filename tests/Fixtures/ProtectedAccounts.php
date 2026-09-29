<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Tests\Fixtures;

use Twstec\Kit\Auth\Contracts\AccountProtection;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Exceptions\AccountProtectedException;

/**
 * Uma extensão que PROTEGE contas (como a demonstração do kit faz com as
 * contas de credenciais públicas): as contas com e-mail em `$emails` são
 * reservadas e a proteção vale.
 */
final class ProtectedAccounts implements AccountProtection
{
    /**
     * @param  list<string>  $emails
     */
    public function __construct(private readonly array $emails) {}

    public function reserves(AuthUser $user): bool
    {
        return in_array((string) $user->getEmailForVerification(), $this->emails, true);
    }

    public function protects(AuthUser $user): bool
    {
        return $this->reserves($user);
    }

    public function guardUpdate(AuthUser $user): void
    {
        if ($this->protects($user) && method_exists($user, 'isDirty') && $user->isDirty(['is_admin', 'status', 'email', 'password'])) {
            throw new AccountProtectedException('Conta protegida pela extensão de teste.');
        }
    }

    public function guardDelete(AuthUser $user): void
    {
        if ($this->protects($user)) {
            throw new AccountProtectedException('Conta protegida pela extensão de teste.');
        }
    }
}
