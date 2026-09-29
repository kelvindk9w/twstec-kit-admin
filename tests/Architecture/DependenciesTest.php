<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// ARQUITETURA DO PACOTE admin — conhece os quatro pacotes do kit de baixo
// (foundation, auth, accounts, uploads), o Laravel e o Filament (com o
// Livewire, que o Filament usa), e só.
//
// admin é a camada de cima do kit: as telas do super admin. Nada abaixo
// depende dele, e ele nunca nomeia o aplicativo. Este arquivo reprova o build
// quando:
//
// 1. qualquer arquivo do pacote (código, config, traduções, views) nomeia
//    classe do APLICATIVO (App\…, inclusive o model de usuário — o pacote
//    trabalha com o model configurado em auth.providers.users.model) ou da
//    demonstração;
// 2. um `use` ou nome totalmente qualificado sai do que o pacote pode usar: o
//    próprio pacote, os quatro pacotes do kit, o Laravel (Illuminate), o
//    Filament, o Livewire, o HttpFoundation do Symfony (que o Laravel usa nas
//    respostas) e o Carbon (a biblioteca de datas que o Laravel usa e expõe);
// 3. o composer.json declara algo além disso.
//
// A leitura do PHP é por tokens: comentários e strings não contam, só nomes
// de classe de verdade (`use`, `new`, `::class`, tipos, `instanceof`…). Os
// nomes antigos (App\Filament\…) aparecem só como TEXTO na lista de apelidos
// (src/Compat) — e é justamente por serem texto que não contam. Nas views
// Blade, que não são PHP puro, vale o nome escrito.
// =============================================================================

const ADMIN_FORBIDDEN_PREFIXES = [
    'App\\',
    'Database\\',
];

const ADMIN_ALLOWED_ROOTS = [
    'Twstec\\Kit\\Admin\\',
    'Twstec\\Kit\\Uploads\\',
    'Twstec\\Kit\\Accounts\\',
    'Twstec\\Kit\\Auth\\',
    'Twstec\\Kit\\Foundation\\',
    'Illuminate\\',
    'Filament\\',
    'Livewire\\',
    'Symfony\\Component\\HttpFoundation\\',
    'Carbon\\',
];

const ADMIN_SHIPPED_DIRECTORIES = ['src', 'config', 'lang'];

function adminRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Nomes de classe de um arquivo PHP, lidos dos tokens: todos os qualificados
 * (`$all`) e só os que são certamente absolutos — os dos `use` do topo e os
 * escritos com `\` na frente (`$absolute`).
 *
 * @return array{all: list<string>, absolute: list<string>}
 */
function adminClassNamesIn(string $contents): array
{
    $all = [];
    $absolute = [];
    $inUse = false;
    $depth = 0;

    foreach (PhpToken::tokenize($contents) as $token) {
        if ($token->text === '{') {
            $depth++;
        } elseif ($token->text === '}') {
            $depth--;
        }

        if ($token->is(T_USE) && $depth === 0) {
            $inUse = true;

            continue;
        }

        if ($inUse && $token->text === ';') {
            $inUse = false;
        }

        if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = ltrim($token->text, '\\');
        $all[$name] = true;

        if ($inUse || $token->is(T_NAME_FULLY_QUALIFIED)) {
            $absolute[$name] = true;
        }
    }

    return ['all' => array_keys($all), 'absolute' => array_keys($absolute)];
}

/**
 * @return array<string, list<string>> caminho relativo => violações
 */
function adminDependencyViolations(): array
{
    $violations = [];

    foreach (ADMIN_SHIPPED_DIRECTORIES as $directory) {
        foreach ((new Finder)->files()->in(adminRoot().'/'.$directory)->name('*.php') as $file) {
            $path = str_replace(adminRoot().'/', '', $file->getRealPath());
            $names = adminClassNamesIn($file->getContents());

            foreach ($names['all'] as $name) {
                foreach (ADMIN_FORBIDDEN_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $violations[$path][] = "usa {$name}";
                    }
                }
            }

            foreach ($names['absolute'] as $name) {
                $allowed = false;

                foreach (ADMIN_ALLOWED_ROOTS as $root) {
                    $allowed = $allowed || str_starts_with($name.'\\', $root);
                }

                // Funções e constantes globais importadas não têm `\`.
                if (! $allowed && str_contains($name, '\\')) {
                    $violations[$path][] = "usa {$name} (fora dos pacotes do kit + Laravel + Filament + Livewire)";
                }
            }
        }
    }

    ksort($violations);

    return $violations;
}

it('não nomeia nada do aplicativo nem da demonstração', function (): void {
    $violations = [];

    foreach (adminDependencyViolations() as $path => $problems) {
        foreach (array_unique($problems) as $problem) {
            $violations[] = "{$path} {$problem}";
        }
    }

    expect($violations)->toBe([]);
});

it('as views do pacote não nomeiam o aplicativo nem usam componente Blade dele', function (): void {
    $violations = [];

    foreach ((new Finder)->files()->in(adminRoot().'/resources/views')->name('*.blade.php') as $file) {
        $conteudo = $file->getContents();
        $path = str_replace(adminRoot().'/', '', $file->getRealPath());

        if (preg_match('/App\\\\+/', $conteudo) === 1) {
            $violations[] = "{$path} nomeia App\\";
        }

        // Só componentes do Filament (x-filament…) e slots: um <x-flag> ou
        // <x-locale-switcher> seria componente do aplicativo.
        preg_match_all('/<x-([a-z0-9.:-]+)/', $conteudo, $componentes);

        foreach ($componentes[1] as $componente) {
            if (! str_starts_with($componente, 'filament') && $componente !== 'slot') {
                $violations[] = "{$path} usa <x-{$componente}>";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('declara no composer.json as dependências que usa — foundation e auth obrigatórios, accounts e uploads opcionais, o Laravel, o Filament e o Livewire', function (): void {
    $composer = json_decode((string) file_get_contents(adminRoot().'/composer.json'), true);

    // accounts e uploads são OPCIONAIS para o painel (ele se adapta ao que
    // está instalado — ver AdminPlugin::RESOURCES): sugeridos, e instalados
    // só para a suíte do pacote.
    expect(array_keys($composer['require']))->toBe([
        'php',
        'filament/filament',
        'laravel/framework',
        'livewire/livewire',
        'twstec/kit-auth',
        'twstec/kit-foundation',
    ])
        ->and(array_keys($composer['suggest']))->toBe(['twstec/kit-accounts', 'twstec/kit-uploads'])
        ->and($composer['require-dev'])->toHaveKeys(['twstec/kit-accounts', 'twstec/kit-uploads']);
});

it('só usa accounts e uploads onde pergunta antes se o módulo está instalado', function (): void {
    // Todo arquivo que nomeia uma classe de accounts ou uploads está na lista
    // revisada abaixo — e cada um confere Kit::has() (ou é uma tela, widget ou
    // campo que só é registrado quando o módulo existe). Arquivo novo que
    // passe a nomear um dos dois reprova aqui até ser revisado.
    $registradosSoComOModulo = [
        // Registrados só com o módulo (AdminPlugin::RESOURCES e os dashboards).
        'src/Resources/Accounts/AccountResource.php',
        'src/Resources/ApiKeys/ApiKeyResource.php',
        'src/Resources/Projects/ProjectResource.php',
        'src/Resources/Uploads/UploadResource.php',
        'src/Widgets/Growth/RecentProjectsTable.php',
        'src/Widgets/Overview/LatestUploads.php',
    ];

    $perguntamAntes = [
        'src/Http/Middleware/OperateAdminPanelAsSystem.php',
        'src/Resources/AuditEvents/AuditEventResource.php',
        'src/Resources/Users/Support/UserAdminGuard.php',
        'src/Support/AvatarUpload.php',
        'src/Widgets/Growth/GrowthStats.php',
        'src/Widgets/Overview/OverviewStats.php',
    ];

    $usam = [];

    foreach ((new Finder)->files()->in(adminRoot().'/src')->name('*.php') as $file) {
        $path = str_replace(adminRoot().'/', '', $file->getRealPath());

        foreach (adminClassNamesIn($file->getContents())['all'] as $name) {
            if (str_starts_with($name, 'Twstec\\Kit\\Accounts\\') || str_starts_with($name, 'Twstec\\Kit\\Uploads\\')) {
                $usam[$path] = true;
            }
        }
    }

    $usam = array_keys($usam);
    $revisados = [...$registradosSoComOModulo, ...$perguntamAntes];
    sort($usam);
    sort($revisados);

    expect($usam)->toBe($revisados);

    foreach ($perguntamAntes as $path) {
        expect((string) file_get_contents(adminRoot().'/'.$path))->toMatch('/Kit::has\\(|AvatarUpload::available\\(/');
    }
});

it('a leitura por tokens pega o que deve pegar (a trava não é cega)', function (): void {
    $sample = <<<'PHP'
        <?php
        namespace Twstec\Kit\Admin\Algo;
        use App\Models\User;
        use Twstec\Kit\Uploads\Models\Upload;
        use Spatie\Coisa\Qualquer;
        // App\Demo\Coisa em comentário não conta
        $a = new \Filament\Panel;
        $b = Support\AdminAudit::class;
        $c = 'App\\Filament\\Em\\String';
        PHP;

    $names = adminClassNamesIn($sample);

    expect($names['absolute'])->toBe(['App\Models\User', 'Twstec\Kit\Uploads\Models\Upload', 'Spatie\Coisa\Qualquer', 'Filament\Panel'])
        ->and($names['all'])->toContain('Support\AdminAudit')
        ->and($names['all'])->not->toContain('App\Demo\Coisa')
        ->and($names['all'])->not->toContain('App\Filament\Em\String');
});
