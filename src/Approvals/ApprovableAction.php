<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Twstec\Kit\Admin\Support\AdminAudit;

/**
 * Uma ação do /admin que PODE exigir aprovação em dois passos.
 *
 * O aplicativo declara a ação uma vez — o que ela faz, quais guardas valem e
 * o que o aprovador precisa ver — e registra no provider dele:
 *
 *   Approvals::register(CancelOrder::class);
 *
 * Ela passa a exigir aprovação quando a chave está em
 * `admin.approvals.actions` (ADMIN_APPROVALS_ACTIONS). Na tela, a Action do
 * Filament passa por Approvals::gate(): com a aprovação ligada, em vez de
 * executar, pede o motivo e cria o pedido; sem ela, executa como sempre.
 *
 * O ApprovalService chama, nesta ordem:
 * - no PEDIDO: denial() (com quem pede), changes() e fingerprint();
 * - na APROVAÇÃO/EXECUÇÃO, sob a trava do pedido: resolveSubject(),
 *   fingerprint() de novo (mudou = o pedido fica obsoleto, nada executa),
 *   denial() com quem aprova e, só então, execute() — uma vez.
 */
abstract class ApprovableAction
{
    /**
     * Chave estável (`users.delete`, `orders.cancel`). É também a permissão
     * que quem pede e quem aprova precisam ter (permission()).
     */
    abstract public function key(): string;

    /**
     * Classe do model alvo (resolvido pelo `uuid`, nunca pelo id interno).
     *
     * @return class-string<Model>
     */
    abstract public function subjectModel(): string;

    /**
     * Executa a ação — dentro da transação da aprovação, com o pedido
     * travado. Exceção = a execução é desfeita e o pedido fica `failed`.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function execute(Model $subject, array $data, Authenticatable $actor): void;

    /**
     * Nome na tela (lista de pedidos, notificações).
     */
    public function label(): string
    {
        return Str::headline(str_replace('.', ' ', $this->key()));
    }

    /**
     * Esta ação exige aprovação SEMPRE, pela natureza dela — sem depender de
     * estar em `admin.approvals.actions`. A config só ACRESCENTA ações que
     * exigem aprovação; nunca tira a exigência de uma ação que a declara aqui.
     */
    public function alwaysRequiresApproval(): bool
    {
        return false;
    }

    /**
     * A permissão para pedir e para aprovar (além de `approvals.approve`).
     */
    public function permission(): string
    {
        return $this->key();
    }

    /**
     * Guarda de servidor: o motivo da recusa, ou null. A MESMA regra do
     * caminho sem aprovação — conferida de novo na aprovação, com quem
     * aprova como ator.
     *
     * @param  array<string, mixed>  $data
     */
    public function denial(Model $subject, array $data, Authenticatable $actor): ?string
    {
        return null;
    }

    /**
     * O antes/depois que o aprovador lê: `{campo: {before, after}}`. Passa
     * pela mesma redação da trilha (segredo mascarado, dado pessoal cifrado
     * vira iniciais, e-mail/CPF/cartão mascarados).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{before?: mixed, after?: mixed}>
     */
    public function changes(Model $subject, array $data): array
    {
        return [];
    }

    /**
     * O estado do alvo que, se mudar entre o pedido e a execução, invalida o
     * pedido. Padrão: todos os atributos gravados, menos as datas de
     * criação/atualização. Sobrescreva para olhar só o que importa à ação.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fingerprint(Model $subject, array $data): array
    {
        $attributes = $subject->getAttributes();

        unset($attributes['created_at'], $attributes['updated_at']);

        ksort($attributes);

        return ['subject' => $attributes, 'data' => $data];
    }

    /**
     * O alvo pelo uuid (null = não existe mais).
     */
    public function resolveSubject(string $uuid): ?Model
    {
        $model = $this->subjectModel();

        return $model::query()->where('uuid', $uuid)->first();
    }

    /**
     * Verbo da execução na trilha, quando ela atualiza o alvo
     * (`<tipo>.<verbo>`). Padrão: a parte depois do ponto da chave, no
     * particípio que o AdminAudit conhece (`delete` → `deleted`).
     */
    public function verb(): string
    {
        $name = Str::afterLast($this->key(), '.');

        return AdminAudit::verb(Str::camel($name));
    }
}
