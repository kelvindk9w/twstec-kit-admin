<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;

// =============================================================================
// O APLICATIVO VENCE O PACOTE NAS TRADUÇÕES — a mesma regra do foundation, do
// auth, do accounts e do uploads (Twstec\Kit\Foundation\Localization\PackageTranslations),
// valendo para as traduções deste pacote.
//
// Quem usa o kit troca qualquer texto do painel editando o lang/ do próprio
// aplicativo: numa mesma chave vale o texto do aplicativo; o pacote só
// preenche o que o aplicativo não definiu — nos três idiomas e no idioma de
// reserva.
// =============================================================================

/**
 * Troca a pasta lang/ do aplicativo por uma temporária com estes arquivos e
 * refaz o carregador de traduções (como num boot novo).
 *
 * @param  array<string, array<string, mixed>>  $files  'pt_BR/admin.php' => conteúdo
 */
function adminAppLang(array $files): string
{
    $dir = sys_get_temp_dir().'/admin-app-lang-'.uniqid();

    foreach ($files as $relative => $contents) {
        @mkdir(dirname($dir.'/'.$relative), 0755, true);
        file_put_contents($dir.'/'.$relative, '<?php return '.var_export($contents, true).';');
    }

    app()->useLangPath($dir);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');

    return $dir;
}

function adminPackageLang(string $relative): array
{
    return require dirname(__DIR__, 2).'/lang/'.$relative;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/admin-app-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) chave só no pacote: texto do pacote', function (string $locale): void {
    adminAppLang(["{$locale}/outro.php" => ['x' => 'y']]);

    $pacote = adminPackageLang("{$locale}/admin.php");
    $panel = adminPackageLang("{$locale}/panel.php");

    expect(__('admin.users.plural', [], $locale))->toBe($pacote['users']['plural'])
        ->and(__('admin.users.cannot_block_self', [], $locale))->toBe($pacote['users']['cannot_block_self'])
        ->and(__('panel.common.name', [], $locale))->toBe($panel['common']['name']);
})->with(['pt_BR', 'en', 'es']);

it('(b) mesma chave no aplicativo: texto do aplicativo', function (string $locale): void {
    adminAppLang([
        "{$locale}/admin.php" => ['users' => ['plural' => "Pessoas do app ({$locale})"]],
        "{$locale}/panel.php" => ['common' => ['name' => "Nome do app ({$locale})"]],
    ]);

    expect(__('admin.users.plural', [], $locale))->toBe("Pessoas do app ({$locale})")
        ->and(__('panel.common.name', [], $locale))->toBe("Nome do app ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) grupo repartido: cada chave resolve do lado certo — app e pacote no mesmo grupo', function (string $locale): void {
    adminAppLang(["{$locale}/admin.php" => ['users' => ['blocked' => "Barrada no app ({$locale})"], 'products' => ['label' => 'Só do app']]]);

    $pacote = adminPackageLang("{$locale}/admin.php");

    expect(__('admin.users.blocked', [], $locale))->toBe("Barrada no app ({$locale})")
        ->and(__('admin.users.active', [], $locale))->toBe($pacote['users']['active'])
        ->and(__('admin.products.label', [], $locale))->toBe('Só do app')
        ->and(__('admin.audit.plural', [], $locale))->toBe($pacote['audit']['plural']);
})->with(['pt_BR', 'en', 'es']);

it('no idioma de reserva o aplicativo também vence, e o pacote preenche o idioma pedido', function (): void {
    adminAppLang(['pt_BR/admin.php' => ['users' => ['plural' => 'Pessoas (reserva do app)']]]);

    app('translator')->setFallback('pt_BR');

    expect(__('admin.users.plural', [], 'fr'))->toBe('Pessoas (reserva do app)')
        ->and(__('admin.users.plural', [], 'en'))->toBe(adminPackageLang('en/admin.php')['users']['plural']);
});

it('a tela do painel mostra o texto do aplicativo quando ele o define', function (): void {
    adminAppLang(['en/admin.php' => ['users' => ['create' => 'Cadastrar pessoa (app)']]]);

    $this->actingAs($this->admin());

    Livewire::test(ListUsers::class)->assertSee('Cadastrar pessoa (app)');
});

it('põe a pasta do pacote antes da do aplicativo no carregador, junto com as dos outros pacotes do kit', function (): void {
    $dir = adminAppLang(['pt_BR/outro.php' => ['x' => 'y']]);

    app('translator');

    $paths = app('translation.loader')->paths();
    $posicao = fn (string|false $path): int|false => array_search($path, array_map(fn (string $p): string => realpath($p) ?: $p, $paths), true);

    expect($posicao($dir))->toBe(count($paths) - 1);

    foreach (['/lang', '/vendor/twstec/kit-uploads/lang', '/vendor/twstec/kit-accounts/lang', '/vendor/twstec/kit-auth/lang', '/vendor/twstec/kit-foundation/lang'] as $pasta) {
        $pacote = realpath(dirname(__DIR__, 2).$pasta);

        expect($posicao($pacote))->toBeInt()
            ->and($posicao($pacote))->toBeLessThan($posicao($dir));
    }
});
