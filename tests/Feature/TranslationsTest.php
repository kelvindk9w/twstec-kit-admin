<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

// As traduções do pacote: mesmas chaves nos três idiomas, resolvidas pelos
// nomes de sempre (sem namespace) e somadas às do aplicativo grupo a grupo.
// Além do grupo `admin`, o pacote traz as poucas chaves dos grupos `auth` e
// `panel` que as telas dele usam e que nenhum pacote abaixo traz (no starter,
// o próprio lang/ do aplicativo as define — e vence).

const ADMIN_LOCALES = ['pt_BR', 'en', 'es'];

function adminLangPath(string $relative): string
{
    return dirname(__DIR__, 2).'/lang/'.$relative;
}

/**
 * Chaves literais `__('grupo.chave')` pedidas pelo código e pelas views do
 * pacote, por grupo.
 *
 * @return list<string>
 */
function adminRequestedKeys(): array
{
    $pedidas = [];
    $raiz = dirname(__DIR__, 2);

    foreach (['src', 'resources/views'] as $pasta) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/'.$pasta)) as $file) {
            if (! str_ends_with((string) $file, '.php')) {
                continue;
            }

            // Só a chave INTEIRA (`__('a.b')` ou `__('a.b', …)`); prefixo
            // concatenado (`__('a.b_'.$x)`) não é chave.
            preg_match_all("/__\\('((?:admin|auth|panel)\\.[a-zA-Z0-9_.]+[a-zA-Z0-9_])'\\s*[,)]/", (string) file_get_contents((string) $file), $matches);
            array_push($pedidas, ...$matches[1]);
        }
    }

    return array_values(array_unique($pedidas));
}

it('todos os idiomas têm os mesmos arquivos e chaves do pt-BR', function (): void {
    $reference = [];

    foreach (glob(adminLangPath('pt_BR/*.php')) ?: [] as $file) {
        $reference[basename($file)] = collect(require $file)->dot()->keys()->sort()->values();
    }

    expect(array_keys($reference))->toBe(['admin.php']);

    foreach (['en', 'es'] as $locale) {
        foreach ($reference as $file => $keys) {
            $path = adminLangPath("{$locale}/{$file}");

            expect($path)->toBeFile();

            $actual = collect(require $path)->dot()->keys()->sort()->values();

            expect($actual->all())->toBe($keys->all(), "lang/{$locale}/{$file} diverge do pt-BR");
        }
    }
});

it('resolve cada chave do pacote pelo nome de sempre, nos três idiomas', function (string $locale): void {
    foreach (glob(adminLangPath('pt_BR/*.php')) ?: [] as $file) {
        $group = basename($file, '.php');

        foreach (collect(require $file)->dot()->keys() as $key) {
            expect(app('translator')->hasForLocale("{$group}.{$key}", $locale))
                ->toBeTrue("Falta {$group}.{$key} em {$locale}");
        }
    }
})->with(ADMIN_LOCALES);

it('toda chave que o código e as views do pacote pedem existe — no pacote ou num pacote de baixo, nunca só no aplicativo', function (): void {
    $pedidas = adminRequestedKeys();
    $faltando = [];

    foreach ($pedidas as $key) {
        [$group, $item] = explode('.', $key, 2);

        foreach (ADMIN_LOCALES as $locale) {
            $fontes = [adminLangPath("{$locale}/{$group}.php")];

            // Os textos de autenticação e do painel do usuário que o
            // twstec/kit-auth traz (o /admin reaproveita os mesmos rótulos).
            if (in_array($group, ['auth', 'panel'], true)) {
                $fontes[] = dirname(__DIR__, 2).'/vendor/twstec/kit-auth/lang/'.$locale.'/'.$group.'.php';
            }

            $achou = false;

            foreach ($fontes as $fonte) {
                $achou = $achou || (is_file($fonte) && Arr::has(require $fonte, $item));
            }

            if (! $achou) {
                $faltando[] = "{$key} ({$locale})";
            }
        }
    }

    expect(count($pedidas))->toBeGreaterThan(150)
        ->and($faltando)->toBe([]);
});

it('os textos de auth e do painel do usuário não se repetem aqui: são do twstec/kit-auth (cada texto tem um dono)', function (string $locale): void {
    expect(adminLangPath("{$locale}/auth.php"))->not->toBeFile()
        ->and(adminLangPath("{$locale}/panel.php"))->not->toBeFile()
        ->and(__('panel.common.save', [], $locale))->toBe((require dirname(__DIR__, 2).'/vendor/twstec/kit-auth/lang/'.$locale.'/panel.php')['common']['save'])
        ->and(__('auth.two_factor.title', [], $locale))->toBe((require dirname(__DIR__, 2).'/vendor/twstec/kit-auth/lang/'.$locale.'/auth.php')['two_factor']['title']);
})->with(ADMIN_LOCALES);

it('o grupo admin do pacote não traz texto da demonstração do kit', function (): void {
    $admin = require adminLangPath('pt_BR/admin.php');

    expect($admin)->not->toHaveKey('products')
        ->and(array_keys($admin['submissions']))->each->toStartWith('attack_')
        ->and($admin['dashboards'])->not->toHaveKey('content')
        ->and($admin['audit'])->not->toHaveKey('type_product');
});

it('as mensagens de conta protegida são neutras — valem para qualquer conta protegida, não só a da demo', function (string $locale): void {
    // O produto não conhece a demonstração: quem protege uma conta é a
    // extensão registrada em AccountProtection. Com a demo instalada, ela dá o
    // texto "de demo" a estas chaves (twstec/kit-demo).
    $admin = require adminLangPath("{$locale}/admin.php");

    foreach ([
        $admin['users']['account_protected'],
        $admin['command']['account_protected'],
        $admin['profile']['email_readonly_note'],
        $admin['profile']['password_note'],
    ] as $texto) {
        expect(mb_strtolower($texto))->not->toContain('demo');
    }

    // Os nomes antigos (até a 2.x) continuam resolvendo, com o mesmo texto.
    expect(__('admin.users.demo_protected', [], $locale))->toBe(__('admin.users.account_protected', [], $locale))
        ->and(__('admin.command.demo_protected', ['email' => 'x'], $locale))->toBe(__('admin.command.account_protected', ['email' => 'x'], $locale));
})->with(ADMIN_LOCALES);
