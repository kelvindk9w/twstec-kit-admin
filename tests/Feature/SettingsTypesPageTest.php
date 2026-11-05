<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;
use Twstec\Kit\Admin\Pages\Settings;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Settings\Models\Setting;
use Twstec\Kit\Foundation\Settings\SettingsManager;

// =============================================================================
// A TELA DE CONFIGURAÇÕES com os tipos da whitelist (config/settings.php):
// número (int), escolha Ligado/Desligado (bool), escolha entre as opções
// (enum) e texto curto (string). Cada campo grava no tipo certo, recusa o que
// não serve e deixa `setting.changed` na trilha; vazio volta ao .env.
// =============================================================================

beforeEach(function (): void {
    config()->set('settings.overrides', [
        'demo.limit' => ['type' => 'int', 'min' => 1, 'max' => 10, 'group' => 'other'],
        'demo.banner' => ['type' => 'bool', 'group' => 'other'],
        'demo.channel' => ['type' => 'enum', 'options' => ['email', 'sms'], 'group' => 'other'],
        'demo.tagline' => ['type' => 'string', 'max_length' => 12, 'group' => 'other'],
        'demo.support_url' => ['type' => 'string', 'pattern' => '#^https://#', 'group' => 'other'],
    ]);
    config()->set('demo', ['limit' => 5, 'banner' => false, 'channel' => 'email', 'tagline' => 'do .env', 'support_url' => null]);

    $this->actingAs($this->admin());
});

it('cada tipo ganha o campo dele', function (): void {
    Livewire::test(Settings::class)
        ->assertOk()
        ->assertFormFieldExists('demo_limit', fn (TextInput $field): bool => $field->isNumeric())
        ->assertFormFieldExists('demo_banner', fn (Select $field): bool => array_keys($field->getOptions()) === [1, 0])
        ->assertFormFieldExists('demo_channel', fn (Select $field): bool => array_keys($field->getOptions()) === ['email', 'sms'])
        ->assertFormFieldExists('demo_tagline', fn (TextInput $field): bool => $field->getMaxLength() === 12 && ! $field->isNumeric());
});

it('salva bool, enum e string no tipo certo, com a linha na trilha', function (): void {
    Livewire::test(Settings::class)
        ->set('data.demo_banner', '1')
        ->set('data.demo_channel', 'sms')
        ->set('data.demo_tagline', 'Olá, mundo')
        ->set('data.demo_support_url', 'https://ajuda.example.com')
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SettingsManager::class);

    expect($settings->get('demo.banner'))->toBeTrue()
        ->and($settings->get('demo.channel'))->toBe('sms')
        ->and($settings->get('demo.tagline'))->toBe('Olá, mundo')
        ->and($settings->get('demo.support_url'))->toBe('https://ajuda.example.com')
        ->and($settings->get('demo.limit'))->toBe(5);

    $changes = AuditEvent::query()->where('action', 'setting.changed')->get()
        ->mapWithKeys(fn (AuditEvent $event): array => (array) $event->changes)
        ->all();

    expect($changes['demo.banner'])->toBe(['before' => null, 'after' => true])
        ->and($changes['demo.channel'])->toBe(['before' => null, 'after' => 'sms'])
        ->and($changes['demo.tagline'])->toBe(['before' => null, 'after' => 'Olá, mundo']);
});

it('Desligado grava false (não "vazio"), e o campo reabre com o valor gravado', function (): void {
    Livewire::test(Settings::class)
        ->set('data.demo_banner', '0')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SettingsManager::class)->all())->toHaveKey('demo.banner', false);

    Livewire::test(Settings::class)
        ->assertSet('data.demo_banner', '0')
        ->set('data.demo_banner', null)
        ->call('save');

    expect(Setting::query()->where('key', 'demo.banner')->exists())->toBeFalse();
});

it('recusa na tela o valor que não serve para o tipo, sem gravar', function (string $field, string $value): void {
    Livewire::test(Settings::class)
        ->set('data.'.$field, $value)
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'enum fora das opções' => ['demo_channel', 'pombo'],
    'bool fora de Ligado/Desligado' => ['demo_banner', 'talvez'],
    'string longa demais' => ['demo_tagline', 'treze letras!'],
    'string fora do formato' => ['demo_support_url', 'http://ajuda.example.com'],
    'int acima do máximo' => ['demo_limit', '11'],
]);
