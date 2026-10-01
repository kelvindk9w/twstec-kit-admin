<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals;

use InvalidArgumentException;

/**
 * As ações que podem exigir aprovação, por chave — registradas pelo kit e
 * pelo aplicativo (Approvals::register).
 */
final class ApprovalRegistry
{
    /** @var array<string, ApprovableAction> */
    private array $actions = [];

    /**
     * @param  ApprovableAction|class-string<ApprovableAction>  $action
     */
    public function register(ApprovableAction|string $action): ApprovableAction
    {
        $instance = is_string($action) ? app($action) : $action;

        if (! $instance instanceof ApprovableAction) {
            throw new InvalidArgumentException('Ação de aprovação precisa estender '.ApprovableAction::class.'.');
        }

        return $this->actions[$instance->key()] = $instance;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function get(string $key): ?ApprovableAction
    {
        return $this->actions[$key] ?? null;
    }

    /**
     * @return array<string, ApprovableAction>
     */
    public function all(): array
    {
        return $this->actions;
    }
}
