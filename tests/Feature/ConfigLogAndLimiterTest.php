<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;

// =============================================================================
// CONFIGURAÇÃO, CANAL DE LOG E LIMITADOR — de onde vêm numa aplicação limpa.
//
// - Configuração: o pacote traz `admin` e `dashboards`; a do aplicativo vence
//   chave a chave de primeiro nível (mergeConfigFrom).
// - Canal de log: o pacote NÃO cria canal próprio. A trilha de auditoria usa o
//   canal de requisições do twstec/kit-foundation (`request_log`) como segunda
//   camada, e os avisos de opt-out vão para o canal padrão do aplicativo.
// - Limitador: o pacote NÃO cria limitador próprio. O login do painel usa o
//   limite do Filament, e o segundo fator usa os limites do twstec/kit-auth.
// =============================================================================

it('a configuração do painel vem do pacote numa aplicação que não publicou nada', function (): void {
    expect(config('admin'))->toBe(['protections' => true])
        ->and(config('dashboards.periods'))->toBe([7, 30, 90])
        ->and(config('dashboards.latest_records'))->toBe(6)
        ->and(config('dashboards.goals.monthly_requests'))->toBe(1500);
});

it('a configuração do aplicativo vence a do pacote', function (): void {
    $this->bootWith([
        'admin' => ['protections' => true, 'extra' => 'do-app'],
        'dashboards.latest_records' => 10,
        'dashboards.goals' => ['monthly_requests' => 99],
    ]);

    expect(config('admin.extra'))->toBe('do-app')
        ->and(config('dashboards.latest_records'))->toBe(10)
        ->and(config('dashboards.goals.monthly_requests'))->toBe(99);
});

it('o pacote não cria canal de log: a segunda camada da trilha é o canal de requisições do foundation', function (): void {
    expect(config('logging.channels'))->toHaveKey('request_log')
        ->and(array_filter(array_keys(config('logging.channels')), fn (string $canal): bool => str_contains($canal, 'admin')))->toBe([]);
});

it('o pacote não cria limitador: só os da base (foundation) e do auth', function (): void {
    foreach (['admin', 'kit-admin', 'filament-admin'] as $nome) {
        expect(RateLimiter::limiter($nome))->toBeNull();
    }

    // Os da base continuam lá, com o nome de sempre.
    expect(RateLimiter::limiter('sensitive'))->not->toBeNull();
});
