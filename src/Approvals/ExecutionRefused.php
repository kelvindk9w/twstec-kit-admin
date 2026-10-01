<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals;

use RuntimeException;

/**
 * A ação aprovada RECUSOU na hora de executar, por uma regra de negócio que
 * só aparece agora — não é defeito: um impedimento de exclusão que surgiu
 * depois do pedido, um registro do aplicativo que ainda aponta para o alvo
 * (chave estrangeira RESTRICT) — com uma mensagem JÁ TRADUZIDA para o
 * operador.
 *
 * O ApprovalService trata diferente de uma falha qualquer: nada da execução
 * fica (o ponto de salvamento é desfeito), o pedido vira `failed` com ESTA
 * mensagem em `failure_reason` (sem o nome da classe, sem texto do banco), a
 * recusa vai para a trilha no alvo (`<tipo>.<verbo>`, `denied`, com o motivo)
 * e nada é reportado como erro.
 */
final class ExecutionRefused extends RuntimeException {}
