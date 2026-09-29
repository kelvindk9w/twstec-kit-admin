<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Pages;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Foundation\Settings\SettingsManager;

/**
 * Configurações do sistema (super admin): ajustes operacionais
 * editáveis pela UI, SEM tocar no .env.
 *
 * Somente a whitelist de config/settings.php aparece aqui. Campo VAZIO =
 * volta ao valor do .env (o override da tabela settings é removido). Os
 * valores gravados passam a valer no próximo request (o
 * SettingsServiceProvider os aplica no boot, via cache).
 *
 * TRILHA DE AUDITORIA: cada chave que muda vira uma linha `setting.changed`
 * com o de/para — quem grava é o SettingsManager (nulo = sem sobreposição,
 * vale o .env). Chave que não mudou não gera linha.
 */
final class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'kit-admin::pages.settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.group_system');
    }

    public function getTitle(): string
    {
        return __('admin.settings.heading');
    }

    public function getSubheading(): string
    {
        return __('admin.settings.subheading');
    }

    /**
     * Formulário de leitura confortável: sem teto, seis campos numéricos de
     * 1 a 4 dígitos esticavam a 1000px+ de largura.
     */
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::FourExtraLarge;
    }

    public function mount(SettingsManager $settings): void
    {
        $overrides = $settings->all();

        $state = [];

        foreach (array_keys($settings->whitelist()) as $key) {
            // Vazio quando NÃO há override — o placeholder mostra o valor do .env.
            $state[self::fieldName($key)] = $overrides[$key] ?? null;
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        /** @var SettingsManager $settings */
        $settings = app(SettingsManager::class);

        // Campos AGRUPADOS POR ASSUNTO (chaves de API / uploads / rate limit)
        // e com largura proporcional ao tamanho do número esperado — os dois
        // metadados vêm de config/settings.php, nada hardcoded aqui.
        $grupos = [];

        foreach ($settings->whitelist() as $key => $meta) {
            $grupos[$meta['group'] ?? 'other'][] = TextInput::make(self::fieldName($key))
                ->label(__('admin.settings.key_'.self::fieldName($key)))
                ->helperText(__('admin.settings.env_fallback', ['value' => config($key)]))
                ->numeric()
                ->minValue($meta['min'])
                ->maxValue($meta['max'])
                ->columnSpan($meta['span'] ?? 4)
                ->nullable();
        }

        $sections = [];

        foreach ($grupos as $grupo => $fields) {
            $sections[] = Section::make(__('admin.settings.group_'.$grupo))
                ->description(__('admin.settings.group_'.$grupo.'_hint'))
                ->columns(12)
                ->schema($fields);
        }

        return $schema->components($sections)->statePath('data');
    }

    public function save(SettingsManager $settings): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        // Uma transação: as mudanças e as linhas da trilha entram juntas.
        DB::transaction(function () use ($settings, $state): void {
            foreach (array_keys($settings->whitelist()) as $key) {
                $value = $state[self::fieldName($key)] ?? null;

                // Vazio = remove o override (volta ao .env). Valor = grava.
                $settings->set($key, $value === null || $value === '' ? null : (int) $value);
            }
        });

        Notification::make()
            ->success()
            ->title(__('admin.settings.saved'))
            ->send();
    }

    /**
     * Nome do campo no formulário (pontos da chave de config viram "_" —
     * o Filament interpreta pontos como aninhamento de array).
     */
    private static function fieldName(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
