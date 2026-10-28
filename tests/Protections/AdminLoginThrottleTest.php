<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Admin\Pages\Auth\Login;
use Twstec\Kit\Auth\Actions\AttemptLogin;

// =============================================================================
// O login do /admin aplica o bloqueio do login do aplicativo (AttemptLogin do
// twstec/kit-auth), com os MESMOS contadores: e-mail + cliente (IPv6 pelo
// prefixo) e por conta, de qualquer origem. O limite do Filament conta pelo
// IP exato — trocar de IPv6 dentro do /64, ou usar muitos IPs, dava tentativas
// sem fim contra a senha de um admin.
// =============================================================================

beforeEach(function (): void {
    config()->set('auth.login.max_attempts', 5);
    config()->set('auth.login.max_attempts_per_account', 20);
    config()->set('auth.login.lockout_minutes', 15);
});

/**
 * Uma tentativa no formulário do /admin/login, pelo endpoint do Livewire, a
 * partir do IP dado. Devolve a mensagem de erro do e-mail (ou null).
 */
function adminLoginAttempt(mixed $test, string $email, string $password, string $ip): ?string
{
    return (function () use ($email, $password, $ip): ?string {
        $this->flushSession();
        Auth::guard('web')->logout();

        $html = $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/admin/login')->getContent();
        $snapshot = $this->snapshotFrom((string) $html, Login::class);

        /** @var TestResponse $response */
        $response = $this->livewireCall($snapshot, 'authenticate', ['data.email' => $email, 'data.password' => $password], [], $ip);

        $memo = json_decode((string) $response->json('components.0.snapshot'), true)['memo'] ?? [];

        return $memo['errors']['data.email'][0] ?? null;
    })->call($test);
}

/**
 * O começo da mensagem de bloqueio do login do aplicativo (antes do tempo
 * restante, que varia).
 */
function throttleMessage(): string
{
    $raw = (string) __('auth.throttle');

    return substr($raw, 0, (int) strpos($raw, ':'));
}

it('IPv6 trocando de endereço dentro do mesmo /64 não ganha tentativas novas no /admin/login', function (): void {
    $admin = $this->admin(['email' => 'dono-v6@example.com']);

    foreach (range(1, 5) as $i) {
        expect(adminLoginAttempt($this, 'dono-v6@example.com', 'senha-errada', "2001:db8:1:2::{$i}"))
            ->toBe(__('filament-panels::auth/pages/login.messages.failed'));
    }

    // Sexto endereço, mesmo /64, senha CERTA: bloqueado, com a mensagem do
    // login do aplicativo.
    expect(adminLoginAttempt($this, 'dono-v6@example.com', 'Senha-correta-1', '2001:db8:1:2::99'))->toStartWith(throttleMessage())
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('o limite por CONTA vale somando /login e /admin/login, de IPs diferentes', function (): void {
    config()->set('auth.login.max_attempts_per_account', 4);

    $this->admin(['email' => 'dona-conta@example.com']);

    // Duas erradas no login do aplicativo...
    foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->setLaravelSession(app('session.store'));

        expect(fn () => app(AttemptLogin::class)->handle($request, 'dona-conta@example.com', 'errada', false))
            ->toThrow(ValidationException::class);
    }

    // ...duas no /admin, de outros IPs...
    adminLoginAttempt($this, 'dona-conta@example.com', 'errada', '198.51.100.3');
    adminLoginAttempt($this, 'dona-conta@example.com', 'errada', '198.51.100.4');

    // ...e a conta está bloqueada nos dois, até com a senha certa.
    expect(adminLoginAttempt($this, 'dona-conta@example.com', 'Senha-correta-1', '198.51.100.5'))->toStartWith(throttleMessage())
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(RateLimiter::tooManyAttempts(AttemptLogin::accountThrottleKey('dona-conta@example.com'), 4))->toBeTrue();
});

it('senha certa no /admin/login zera os contadores compartilhados', function (): void {
    $admin = $this->admin(['email' => 'acerta@example.com']);

    adminLoginAttempt($this, 'acerta@example.com', 'errada', '203.0.113.7');
    adminLoginAttempt($this, 'acerta@example.com', 'errada', '203.0.113.7');

    expect(RateLimiter::attempts(AttemptLogin::accountThrottleKey('acerta@example.com')))->toBe(2);

    expect(adminLoginAttempt($this, 'acerta@example.com', 'Senha-correta-1', '203.0.113.7'))->toBeNull()
        ->and(Auth::guard('web')->id())->toBe($admin->getKey())
        ->and(RateLimiter::attempts(AttemptLogin::accountThrottleKey('acerta@example.com')))->toBe(0);
});

it('o limite do próprio Filament (que recusa sem conferir a senha) não zera os contadores compartilhados', function (): void {
    // O limite por cliente do kit acima do do Filament (5 por minuto por IP):
    // a sexta tentativa do mesmo IP é recusada pelo Filament.
    config()->set('auth.login.max_attempts', 100);

    $this->admin(['email' => 'filament-limita@example.com']);

    foreach (range(1, 6) as $ignored) {
        adminLoginAttempt($this, 'filament-limita@example.com', 'errada', '192.0.2.10');
    }

    expect(RateLimiter::attempts(AttemptLogin::accountThrottleKey('filament-limita@example.com')))->toBe(5)
        ->and(Auth::guard('web')->check())->toBeFalse();
});
