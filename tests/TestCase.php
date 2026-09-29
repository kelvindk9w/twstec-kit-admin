<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Testbench;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\Backup\BackupServiceProvider;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Admin\AdminServiceProvider;
use Twstec\Kit\Admin\Tests\Fixtures\AdminPanelProvider;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Auth\Providers\AuthServiceProvider;
use Twstec\Kit\Foundation\Audit\Providers\AuditServiceProvider;
use Twstec\Kit\Foundation\FoundationServiceProvider;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Mail\Providers\MailServiceProvider;
use Twstec\Kit\Foundation\Settings\Providers\SettingsServiceProvider;
use Twstec\Kit\Uploads\UploadsServiceProvider;

/**
 * Aplicação Laravel LIMPA — o esqueleto do Testbench com os providers que a
 * descoberta automática instalaria numa aplicação nova (Filament, Livewire, o
 * backup do Spatie e os pacotes foundation, auth, accounts e uploads), este
 * pacote e um PanelProvider que só declara id, caminho e o AdminPlugin. Nada do starter: nenhuma view, nenhum provider do aplicativo,
 * nenhuma pilha de middleware no painel. Se uma proteção só funcionasse
 * porque o starter lembrou de ligá-la, a suíte reprovaria.
 */
#[WithMigration] // a tabela `users` do esqueleto Laravel; o resto vem das migrations dos pacotes
abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    /**
     * Configuração aplicada ANTES de os providers subirem (como num processo
     * de verdade) — ver bootWith().
     *
     * @var array<string, mixed>
     */
    public static array $scenario = [];

    /**
     * Pasta descartável que faz as vezes de storage/app/private.
     */
    public static ?string $disk = null;

    /**
     * Providers do pacote — os mesmos que a descoberta automática instala
     * (composer.json → extra.laravel; um teste confere).
     *
     * @var list<class-string>
     */
    public const PACKAGE_PROVIDERS = [
        AdminServiceProvider::class,
    ];

    protected function getPackageProviders($app): array
    {
        // Numa aplicação, a descoberta segue a ordem do vendor: os ícones do
        // Blade, o Filament, o Livewire, o backup do Spatie e os pacotes do kit
        // (accounts, admin, auth, foundation, uploads). Depois, os providers
        // do aplicativo — aqui, só o PanelProvider mínimo.
        return [
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            PowerJoinsServiceProvider::class,
            LivewireServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BackupServiceProvider::class,
            AccountsServiceProvider::class,
            ...self::PACKAGE_PROVIDERS,
            AuthServiceProvider::class,
            FoundationServiceProvider::class,
            AuditServiceProvider::class,
            MailServiceProvider::class,
            SettingsServiceProvider::class,
            UploadsServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);

        // O disco `local` do Laravel, como vem — só a raiz vai para uma pasta
        // descartável, para a suíte não escrever dentro do vendor.
        self::$disk ??= sys_get_temp_dir().'/kit-admin-disk-'.uniqid();
        $app['config']->set('filesystems.disks.local.root', self::$disk);

        foreach (static::$scenario as $key => $value) {
            $app['config']->set($key, $value);
        }

        if (isset(static::$scenario['app.env'])) {
            $environment = (string) static::$scenario['app.env'];
            $app->detectEnvironment(static fn (): string => $environment);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        // `is_admin` e `avatar_upload_id` são colunas do APLICATIVO (a
        // migration de usuários dele); aqui, as de uma aplicação que usa o
        // painel.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function tearDown(): void
    {
        if (self::$disk !== null) {
            (new Filesystem)->deleteDirectory(self::$disk);
            self::$disk = null;
        }

        AdminPanelProvider::$before = null;
        AdminPanelProvider::$after = null;

        // Módulo fingido ausente (Kit::pretendAbsent) não vaza para o próximo teste.
        Kit::flushFakes();

        parent::tearDown();
    }

    /**
     * Sobe uma aplicação nova com a configuração dada já valendo no boot.
     *
     * @param  array<string, mixed>  $config
     */
    protected function bootWith(array $config, bool $migrate = true): void
    {
        static::$scenario = $config;

        try {
            $this->refreshApplication();

            if (! $migrate) {
                return;
            }

            $this->loadLaravelMigrations();
            $this->loadMigrationsFrom(__DIR__.'/database/migrations');
            $this->artisan('migrate', ['--force' => true]);
        } finally {
            static::$scenario = [];
        }
    }

    /**
     * Administrador ativo, com e-mail confirmado.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function admin(array $attributes = []): User
    {
        return User::fixture(['is_admin' => true, ...$attributes]);
    }

    /**
     * Snapshot de um componente Livewire numa página já renderizada.
     */
    protected function snapshotFrom(string $html, string $componentName): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            $decoded = json_decode($snapshot, true);

            if (is_array($decoded) && str_contains((string) ($decoded['memo']['name'] ?? ''), $componentName)) {
                return $snapshot;
            }
        }

        throw new \RuntimeException("Componente Livewire [{$componentName}] não encontrado na página.");
    }

    /**
     * Chamada de método ao endpoint de atualização do Livewire, como o cliente
     * JS a envia.
     *
     * @param  array<string, mixed>  $updates
     * @param  array<int, mixed>  $params
     * @param  array<string, string>  $server
     */
    protected function livewireCall(string $snapshot, string $method, array $updates = [], array $params = [], string $ip = '127.0.0.1', array $server = []): TestResponse
    {
        $payload = json_encode([
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => [['method' => $method, 'params' => $params]],
            ]],
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', EndpointResolver::updatePath(), [], [], [], [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LIVEWIRE' => '1',
            ...$server,
        ], $payload);
    }

    /**
     * O painel da "aplicação".
     */
    protected function panel(): Panel
    {
        return Filament::getPanel('admin');
    }
}
