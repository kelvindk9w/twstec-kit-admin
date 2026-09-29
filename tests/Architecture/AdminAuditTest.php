<?php

declare(strict_types=1);

use Filament\Pages\SimplePage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Admin\Pages\Settings;
use Twstec\Kit\Admin\Resources\Users\Pages\EditUser;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// ARQUITETURA da trilha de auditoria do painel — decisão do dono: toda ação de
// admin que altera dado fica registrada no BANCO.
//
// A captura é central (AdminAudit pendura um escopo em toda chamada Livewire
// de tela do painel; AuditTrail grava cada created/updated/deleted de model).
// Este arquivo reprova o build nas portas que a captura sozinha não fecha:
//
// 1. componente do painel fora da cobertura do gancho;
// 2. escrita que NÃO dispara evento de model (query em massa, SQL cru,
//    *Quietly, withoutEvents) — ela mudaria dado sem deixar linha;
// 3. RECUSA sem registro: notificação de erro montada à mão em vez de
//    AdminAudit::denied() (que registra E avisa numa chamada só);
// 4. resource com escrita cujo model a captura ignora;
// 5. painel sem transação (a falha fechada depende dela).
//
// E prova o contrato positivo: uma tela NOVA — do aplicativo ou de uma
// extensão que declarou o namespace — sem uma linha de auditoria, já grava.
// (O starter aplica as mesmas regras ao código dele e ao da demonstração.)
// =============================================================================

/**
 * Arquivos PHP do pacote (src), com o caminho relativo.
 *
 * @return array<string, string> caminho relativo => conteúdo
 */
function adminPackageSources(): array
{
    $raiz = dirname(__DIR__, 2);
    $sources = [];

    foreach ((new Finder)->files()->in($raiz.'/src')->name('*.php') as $file) {
        $sources['src/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
    }

    ksort($sources);

    return $sources;
}

/**
 * @return list<class-string<Component>>
 */
function adminPackageLivewireComponents(): array
{
    $classes = [];

    foreach (array_keys(adminPackageSources()) as $path) {
        $class = 'Twstec\\Kit\\Admin\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($path, 'src/'));

        if (class_exists($class) && is_subclass_of($class, Component::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('todo componente Livewire do pacote está coberto pelo escopo de auditoria (exceto as telas de autenticação)', function (): void {
    $componentes = adminPackageLivewireComponents();
    $cobertos = [];

    expect($componentes)->not->toBeEmpty();

    foreach ($componentes as $classe) {
        if (is_a($classe, SimplePage::class, true)) {
            expect(AdminAudit::covers($classe))->toBeFalse("{$classe} é tela de autenticação e não deveria abrir escopo");

            continue;
        }

        $cobertos[] = $classe;

        expect(AdminAudit::covers($classe))->toBeTrue("{$classe} não passa pela trilha de auditoria");
    }

    expect($cobertos)->toContain(ListUsers::class, EditUser::class, Settings::class, Profile::class);
});

it('nenhuma escrita do pacote passa por fora dos eventos de model (ela sumiria da trilha)', function (): void {
    $proibidos = [
        '/\bDB::(?!transaction\()/' => 'DB:: (use o model; só DB::transaction é permitido)',
        '/Quietly\s*\(/' => '*Quietly() não dispara evento de model',
        '/withoutEvents\s*\(|withoutEventDispatcher/' => 'withoutEvents() desliga a captura',
        '/::truncate\s*\(|->truncate\s*\(/' => 'truncate()',
        '/->upsert\s*\(|::upsert\s*\(/' => 'upsert()',
        '/(?:->|::)insert(?:OrIgnore|GetId|Using)?\s*\(/' => 'insert() em massa',
        '/->(?:increment|decrement)(?:Each)?\s*\(/' => 'increment()/decrement() em massa',
    ];

    $violacoes = [];

    foreach (adminPackageSources() as $path => $source) {
        foreach ($proibidos as $regex => $motivo) {
            if (preg_match($regex, $source) === 1) {
                $violacoes[] = "{$path}: {$motivo}";
            }
        }

        foreach (explode(';', $source) as $sentenca) {
            if (preg_match('/(?:::query\(\)|::where\w*\(|->where\w*\()/', $sentenca) === 1
                && preg_match('/->(?:update|delete|forceDelete)\s*\(/', $sentenca) === 1) {
                $violacoes[] = "{$path}: update/delete em massa numa consulta — ".trim(Str::limit($sentenca, 120));
            }
        }
    }

    expect($violacoes)->toBe([]);
});

it('toda recusa do pacote passa por AdminAudit::denied() — não existe recusar sem registrar', function (): void {
    // Telas de autenticação recusam credencial e código de login, não ação de
    // admin: ninguém está logado ainda. É a única exceção.
    $violacoes = [];

    foreach (adminPackageSources() as $path => $source) {
        if ($path === 'src/Support/AdminAudit.php' || str_starts_with($path, 'src/Auth/') || str_starts_with($path, 'src/Pages/Auth/')) {
            continue;
        }

        if (preg_match('/Notification::make\(\)[^;]*->danger\(\)/s', $source) === 1) {
            $violacoes[] = $path;
        }
    }

    expect($violacoes)->toBe([], 'Recusa montada à mão (Notification ...->danger()) não fica na trilha. Use AdminAudit::denied().');
});

it('resource com escrita não pode ter o model ignorado pela captura', function (): void {
    $trail = app(AuditTrail::class);

    foreach ($this->panel()->getResources() as $resource) {
        $model = new ($resource::getModel());

        if (! $trail->ignores($model)) {
            continue;
        }

        expect($resource::canCreate())->toBeFalse("{$resource} cria registros de um model ignorado pela trilha")
            ->and($resource::hasPage('create') || $resource::hasPage('edit'))->toBeFalse("{$resource} edita um model ignorado pela trilha");
    }
});

it('o painel roda Actions e Criar/Salvar em transação — a base da falha fechada', function (): void {
    expect($this->panel()->hasDatabaseTransactions())->toBeTrue();
});

it('tela de EXTENSÃO só entra na trilha com o namespace declarado — e então grava sem nenhuma linha de auditoria', function (): void {
    require_once dirname(__DIR__).'/Fixtures/Extension/NewExtensionScreen.php';

    $admin = $this->admin();
    $alvo = User::fixture(['name' => 'Cadeira']);
    $this->actingAs($admin);

    config()->set('audit.admin_extension_namespaces', []);
    expect(AdminAudit::covers('Extensao\\Filament\\NewExtensionScreen'))->toBeFalse();

    config()->set('audit.admin_extension_namespaces', ['Extensao\\Filament\\']);
    expect(AdminAudit::covers('Extensao\\Filament\\NewExtensionScreen'))->toBeTrue();

    Livewire::test('Extensao\\Filament\\NewExtensionScreen')->call('renameOld', $alvo->uuid);

    $evento = AuditEvent::query()->where('subject_uuid', $alvo->uuid)->sole();

    expect($evento->action)->toBe('user.rename_old')
        ->and($evento->context)->toBe(AuditContext::Admin)
        ->and($evento->actor_uuid)->toBe($admin->uuid)
        ->and($evento->changes['name'])->toBe(['before' => 'C***', 'after' => 'C*** (***']);
});

it('tela que o APLICATIVO escreve no próprio painel (<namespace do app>\\Filament\\…) já nasce na trilha', function (): void {
    require_once dirname(__DIR__).'/Fixtures/Extension/AppScreen.php';

    // O namespace do aplicativo é o do composer.json dele; aqui, `Aplicacao\`.
    (fn () => $this->namespace = 'Aplicacao\\')->call(app());

    $admin = $this->admin();
    $alvo = User::fixture(['name' => 'Mesa']);
    $this->actingAs($admin);

    config()->set('audit.admin_extension_namespaces', []);

    expect(AdminAudit::covers('Aplicacao\\Filament\\Resources\\AppScreen'))->toBeTrue()
        ->and(AdminAudit::covers('Aplicacao\\Outra\\Coisa'))->toBeFalse();

    Livewire::test('Aplicacao\\Filament\\Resources\\AppScreen')->call('renameOld', $alvo->uuid);

    expect(AuditEvent::query()->where('subject_uuid', $alvo->uuid)->sole()->action)->toBe('user.rename_old');
});
