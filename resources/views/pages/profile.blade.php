<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('panel.common.save') }}
        </x-filament::button>
    </form>

    {{-- Verificação em duas etapas no login — a MESMA preferência e a MESMA
         regra do perfil do painel do cliente (TwoFactorLogin). Ligar/desligar
         abre a confirmação sensível em dois modais: senha de transação →
         código por e-mail. --}}
    @if ($this->twoFactorAvailable())
        <x-filament::section :heading="__('panel.profile.two_factor_heading')"
                             :description="__('panel.profile.two_factor_hint', ['email' => auth()->user()?->email])"
                             data-two-factor-section>
            <x-slot name="afterHeader">
                <x-filament::badge :color="$this->twoFactorEnabled() ? 'success' : 'gray'" data-two-factor-status>
                    {{ $this->twoFactorEnabled() ? __('panel.profile.two_factor_on') : __('panel.profile.two_factor_off') }}
                </x-filament::badge>
            </x-slot>

            <div class="space-y-4">
                @if ($this->twoFactorBlockedReason() !== null)
                    <p class="text-sm text-gray-600 dark:text-gray-300" data-two-factor-blocked>{{ $this->twoFactorBlockedReason() }}</p>
                @endif

                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.profile.two_factor_recovery') }}</p>

                <div>{{ $this->toggleTwoFactorAction }}</div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
