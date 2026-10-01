<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals\Enums;

/**
 * Como um pedido é aprovado — ver config/admin.php (`approvals.mode`).
 */
enum ApprovalMode: string
{
    /** Outra pessoa aprova; a aprovação executa na hora. */
    case FourEyes = 'four_eyes';

    /**
     * O mesmo operador pode aprovar, só com a ação sensível, e executa num
     * segundo passo, depois da espera mínima.
     */
    case SingleOperator = 'single_operator';

    /**
     * O modo configurado. Valor desconhecido cai no mais forte.
     */
    public static function configured(): self
    {
        return self::tryFrom((string) config('admin.approvals.mode', self::FourEyes->value)) ?? self::FourEyes;
    }

    /**
     * O modo que vale para um pedido: o mais forte entre o de quando foi
     * feito e o de agora — trocar a configuração não afrouxa um pedido.
     */
    public static function strictest(self $a, self $b): self
    {
        return $a === self::FourEyes || $b === self::FourEyes ? self::FourEyes : self::SingleOperator;
    }

    public function label(): string
    {
        return __('admin.approvals.mode_'.$this->value);
    }
}
