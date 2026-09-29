<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Tests\Fixtures;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Models\Concerns\KitAuthenticatable;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;
use Twstec\Kit\Uploads\Concerns\HasAvatar;

/**
 * Model de usuário de uma aplicação que NÃO implementou o contrato do Filament
 * (sem `canAccessPanel`). Em ambiente local, o Filament deixa qualquer conta
 * de um model assim entrar no painel; quem recusa é o pacote
 * (EnsureAdminPanelAccess).
 */
class PlainUser extends Authenticatable implements AuthUser, HasLocalePreference
{
    use HasAvatar, HasPublicCode, HasUuids, KitAuthenticatable, Notifiable, RoutesByUuid;

    protected const PUBLIC_CODE_PREFIX = 'USR';

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'locale'];

    protected $hidden = ['password', 'transaction_password', 'remember_token'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'is_admin' => false,
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Dado pessoal criptografado em repouso, como no model do kit: a
            // trilha grava só as iniciais.
            'name' => 'encrypted',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Conta de teste gravada no banco. Campos fora do $fillable (status,
     * is_admin, e-mail confirmado…) entram por forceFill, como a aplicação
     * faria.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fixture(array $attributes = []): static
    {
        $fillable = ['name', 'email', 'password', 'locale'];

        $user = static::createWithPublicCodeRetry([
            'name' => 'Pessoa de Teste',
            'email' => 'pessoa'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Senha-correta-1',
            ...array_intersect_key($attributes, array_flip($fillable)),
        ]);

        $extra = array_diff_key(['email_verified_at' => now(), ...$attributes], array_flip($fillable));

        if ($extra !== []) {
            $user->forceFill($extra)->save();
        }

        return $user;
    }
}
