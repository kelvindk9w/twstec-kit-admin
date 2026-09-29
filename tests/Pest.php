<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Twstec\Kit\Admin\Tests\TestCase;

// Todos os testes do pacote sobem a aplicação limpa do Testbench com a
// descoberta de pacotes, o provider deste pacote e um PanelProvider que só
// registra o AdminPlugin — nada do starter.
pest()->extend(TestCase::class)->in('Feature', 'Protections', 'Architecture');

// Livewire::test de uma tela do painel roda como numa requisição a /admin: com
// o painel "corrente" e já iniciado (é o que o middleware SetUpPanel do
// Filament faz — e é ao iniciar o painel que as Actions passam a rodar em
// transação).
pest()->beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
})->in('Feature', 'Protections', 'Architecture');
