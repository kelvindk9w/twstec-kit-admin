<?php

declare(strict_types=1);

use Twstec\Kit\Admin\Compat\LegacyNames;

// =============================================================================
// Nomes antigos (1.x) → nomes novos, por UMA versão (2.x).
//
// Na 1.x o painel /admin morava no aplicativo, em App\Filament\… (e o comando
// `user:make-admin` em App\Console\Commands\MakeAdminUser); na 2.0 ele é deste
// pacote, em Twstec\Kit\Admin\…. O nome antigo continua resolvendo porque ele
// pode estar gravado fora do código:
//
//   - snapshot de componente Livewire aberto no navegador durante o deploy (o
//     nome do componente de uma página do Filament É o nome da classe; o
//     Livewire procura a classe por esse nome, e o apelido a entrega);
//   - a config de dashboards publicada pelo projeto (`dashboards.variants` e
//     `dashboards.widgets` nomeiam páginas e widgets pela classe);
//   - código do projeto que ainda não trocou o `use` (um resource próprio que
//     estende BaseResource, um widget que estende BaseStatsWidget).
//
// O estado de tabela/visualização guardado NA SESSÃO pelo nome da classe é
// migrado pela Support\LegacySessionState. Não há morph nem `*_type` com essas
// classes: a trilha de auditoria grava o nome curto do MODEL, e o painel não
// tem exports/imports nem jobs com o nome da classe.
//
// O alias é PREGUIÇOSO e a lista é FECHADA (Compat\LegacyNames): só entra
// quando alguém pede um nome antigo que existia, carrega a classe nova e
// registra o nome antigo como apelido dela — a MESMA classe, então
// `instanceof` e type hints aceitam os dois nomes.
//
// O código do kit usa só os nomes novos (um teste de arquitetura do starter
// garante). Estes apelidos saem na 3.0.
// =============================================================================

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $target = LegacyNames::current($class);

    if ($target !== null && (class_exists($target) || interface_exists($target) || trait_exists($target) || enum_exists($target))) {
        class_alias($target, $class);
    }
});
