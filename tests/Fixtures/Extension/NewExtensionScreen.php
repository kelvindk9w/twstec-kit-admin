<?php

declare(strict_types=1);

// Fixture do teste de arquitetura da trilha: uma tela de EXTENSÃO no painel
// (namespace de outro pacote/extensão, como a demonstração do kit), escrita
// SEM nenhuma linha de auditoria. Prova que quem registra é a base (AdminAudit)
// assim que a extensão declara o namespace em
// `audit.admin_extension_namespaces`. Carregada só pelo teste.

namespace Extensao\Filament;

use Illuminate\Support\Facades\Config;
use Livewire\Component;

final class NewExtensionScreen extends Component
{
    public function renameOld(string $uuid): void
    {
        $model = Config::get('auth.providers.users.model');
        $user = $model::query()->where('uuid', $uuid)->firstOrFail();

        $user->forceFill(['name' => $user->name.' (arquivado)'])->save();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
