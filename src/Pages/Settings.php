<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Pages;

use BackedEnum;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\Facades\Lang;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Authorization\Contracts\GuardedByPermission;
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
 *
 * PERMISSÕES: abrir pede `settings.view`; salvar, `settings.update` —
 * conferido no servidor pela checagem central (AdminAuthorization). Sem
 * `update`, o formulário aparece só para leitura.
 */
final class Settings extends Page implements GuardedByPermission, HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'kit-admin::pages.settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function permissionKey(): string
    {
        return 'settings';
    }

    /**
     * @return array<string, string|null>
     */
    public static function actionAbilities(): array
    {
        return [];
    }

    public static function canAccess(): bool
    {
        return AdminPermissions::allows(auth()->user(), 'settings.view');
    }

    public function canSave(): bool
    {
        return AdminPermissions::allows(auth()->user(), 'settings.update');
    }

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

        foreach ($settings->whitelist() as $key => $meta) {
            // Vazio quando NÃO há override — a ajuda mostra o valor do .env.
            $value = $overrides[$key] ?? null;
            $state[self::fieldName($key)] = is_bool($value) ? ($value ? '1' : '0') : $value;
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
            $grupos[$meta['group'] ?? 'other'][] = self::field($key, $meta)
                ->label(__('admin.settings.key_'.self::fieldName($key)))
                ->helperText(__('admin.settings.env_fallback', ['value' => self::display($key, $meta, config($key))]))
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

        return $schema->components($sections)->statePath('data')->disabled(fn (): bool => ! $this->canSave());
    }

    public function save(SettingsManager $settings): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        // Uma transação: as mudanças e as linhas da trilha entram juntas.
        DB::transaction(function () use ($settings, $state): void {
            foreach ($settings->whitelist() as $key => $meta) {
                // Vazio = remove o override (volta ao .env). Valor = grava, no
                // tipo declarado (o SettingsManager recusa o que não serve).
                $settings->set($key, self::typed($meta, $state[self::fieldName($key)] ?? null));
            }
        });

        Notification::make()
            ->success()
            ->title(__('admin.settings.saved'))
            ->send();
    }

    /**
     * O campo de cada tipo (config/settings.php): número com limites,
     * liga/desliga, escolha entre as opções ou texto curto.
     *
     * Liga/desliga é uma escolha (Ligado/Desligado) e não um interruptor: o
     * interruptor não tem o terceiro estado, "vazio = vale o .env".
     *
     * @param  array<string, mixed>  $meta
     */
    private static function field(string $key, array $meta): Field
    {
        $name = self::fieldName($key);

        return match ($meta['type'] ?? 'int') {
            'bool' => Select::make($name)
                ->options(['1' => __('admin.settings.on'), '0' => __('admin.settings.off')])
                ->placeholder(__('admin.settings.use_env')),
            'enum' => Select::make($name)
                ->options(collect(SettingsManager::options($meta))
                    ->mapWithKeys(fn (string $option): array => [$option => self::optionLabel($name, $option)])
                    ->all())
                ->placeholder(__('admin.settings.use_env')),
            'string' => TextInput::make($name)
                ->maxLength(SettingsManager::maxLength($meta))
                ->regex(isset($meta['pattern']) && is_string($meta['pattern']) ? $meta['pattern'] : null),
            default => TextInput::make($name)
                ->numeric()
                ->integer()
                ->minValue(is_int($meta['min'] ?? null) ? $meta['min'] : null)
                ->maxValue(is_int($meta['max'] ?? null) ? $meta['max'] : null),
        };
    }

    /**
     * O valor do formulário no tipo declarado; vazio = null (sem override).
     *
     * @param  array<string, mixed>  $meta
     */
    private static function typed(array $meta, mixed $value): int|bool|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($meta['type'] ?? 'int') {
            'bool' => in_array($value, [true, 1, '1'], true),
            'enum', 'string' => is_scalar($value) ? (string) $value : null,
            default => is_numeric($value) ? (int) $value : null,
        };
    }

    /**
     * O valor do .env na ajuda do campo, legível para o tipo.
     *
     * @param  array<string, mixed>  $meta
     */
    private static function display(string $key, array $meta, mixed $value): string
    {
        return match (true) {
            ($meta['type'] ?? null) === 'bool' => (string) __($value ? 'admin.settings.on' : 'admin.settings.off'),
            ($meta['type'] ?? null) === 'enum' && is_string($value) => self::optionLabel(self::fieldName($key), $value),
            is_scalar($value) => (string) $value,
            default => '—',
        };
    }

    /**
     * Rótulo de uma opção de enum: admin.settings.option_<campo>_<valor>,
     * ou o próprio valor quando o projeto não deu rótulo.
     */
    private static function optionLabel(string $field, string $option): string
    {
        $translation = 'admin.settings.option_'.$field.'_'.$option;

        return Lang::has($translation) ? (string) __($translation) : $option;
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
