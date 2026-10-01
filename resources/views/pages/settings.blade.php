<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($this->canSave())
            <x-filament::button type="submit">
                {{ __('panel.common.save') }}
            </x-filament::button>
        @endif
    </form>
</x-filament-panels::page>
