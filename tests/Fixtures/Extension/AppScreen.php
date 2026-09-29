<?php

declare(strict_types=1);

// Fixture: uma tela que o APLICATIVO escreveu para o próprio painel, no lugar
// em que o Filament as gera (<namespace do app>\Filament\…). Carregada só pelo
// teste, que aponta o namespace do aplicativo para `Aplicacao\`.

namespace Aplicacao\Filament\Resources;

use Illuminate\Support\Facades\Config;
use Livewire\Component;

final class AppScreen extends Component
{
    public function renameOld(string $uuid): void
    {
        $model = Config::get('auth.providers.users.model');
        $user = $model::query()->where('uuid', $uuid)->firstOrFail();

        $user->forceFill(['name' => $user->name.' (do app)'])->save();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
