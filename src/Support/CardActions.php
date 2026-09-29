<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Semântica visual das ações de registro no MODO CARDS das listagens do
 * super admin.
 *
 * O problema: no card, uma fileira de links de texto ("Visualizar",
 * "Editar", "Bloquear", "Excluir") come metade do rodapé, quebra em duas
 * linhas no celular e faz todo cartão parecer um formulário. Na tabela o
 * texto ajuda (a linha é densa e o operador varre coluna a coluna); no
 * card, não.
 *
 * A regra do painel passa a ser: NO CARD a ação é SÓ ÍCONE, com a cor
 * dizendo o que ela faz e o nome aparecendo no hover (tooltip). O rodapé
 * do card divide o espaço em partes iguais — N ações, N colunas de mesma
 * largura, cada ícone centralizado na sua parte (a grade fica no CSS do
 * tema do aplicativo: resources/css/filament.css no starter, `.fi-ta-content-grid`).
 *
 * NO MODO TABELA nada muda: continua o padrão do Filament (link com
 * rótulo). Por isso esta classe só é acionada quando ViewMode é Grid —
 * ver BaseResource::table().
 *
 * CORES (semântica, não decoração):
 * - info    → consulta, não altera nada (Visualizar);
 * - success → constrói ou devolve acesso (Editar, Desbloquear, Restaurar,
 *             Marcar e-mail como verificado);
 * - danger  → tira acesso ou destrói (Bloquear, Excluir, Revogar);
 * - warning → substitui um segredo em uso (Rotacionar);
 * - gray    → neutra (abrir arquivo, baixar).
 *
 * Ação com nome fora do mapa NÃO é adivinhada: mantém a cor e o ícone que
 * o resource declarou e só vira botão de ícone. Assim uma ação nova nasce
 * neutra em vez de nascer vermelha por acidente.
 */
final class CardActions
{
    /**
     * Ícone + cor por NOME de ação (o nome é o contrato: `ViewAction` se
     * chama `view`, `DeleteAction` se chama `delete`, e as ações próprias
     * dos resources declaram o nome no `Action::make()`).
     *
     * @var array<string, array{icon: Heroicon, color: string}>
     */
    private const SEMANTICS = [
        'view' => ['icon' => Heroicon::OutlinedEye, 'color' => 'info'],
        'edit' => ['icon' => Heroicon::OutlinedPencilSquare, 'color' => 'success'],
        'delete' => ['icon' => Heroicon::OutlinedTrash, 'color' => 'danger'],
        'forceDelete' => ['icon' => Heroicon::OutlinedTrash, 'color' => 'danger'],
        'restore' => ['icon' => Heroicon::OutlinedArrowUturnLeft, 'color' => 'success'],
        'block' => ['icon' => Heroicon::OutlinedLockClosed, 'color' => 'danger'],
        'unblock' => ['icon' => Heroicon::OutlinedLockOpen, 'color' => 'success'],
        'markEmailVerified' => ['icon' => Heroicon::OutlinedCheckBadge, 'color' => 'success'],
        'revoke' => ['icon' => Heroicon::OutlinedNoSymbol, 'color' => 'danger'],
        'rotate' => ['icon' => Heroicon::OutlinedArrowPath, 'color' => 'warning'],
        'open' => ['icon' => Heroicon::OutlinedArrowTopRightOnSquare, 'color' => 'gray'],
        'download' => ['icon' => Heroicon::OutlinedArrowDownTray, 'color' => 'gray'],
    ];

    /**
     * Aplica o estilo de card à ação, no lugar (o Filament reaproveita a
     * MESMA instância para cada registro renderizado).
     */
    public static function style(Action $action): void
    {
        $semantics = self::SEMANTICS[$action->getName()] ?? null;

        if ($semantics !== null) {
            $action->icon($semantics['icon'])->color($semantics['color']);
        }

        $action
            ->iconButton()
            // O rótulo continua existindo (vira aria-label do botão): quem
            // navega por leitor de tela ouve "Excluir", não "botão".
            ->tooltip(fn (): ?string => $action->getLabel());
    }

    /**
     * A cor semântica de um nome de ação — exposta para os testes provarem
     * o contrato sem depender da renderização.
     */
    public static function colorFor(string $name): ?string
    {
        return self::SEMANTICS[$name]['color'] ?? null;
    }
}
