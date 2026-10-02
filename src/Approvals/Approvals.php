<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Approvals;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Resources\ApprovalRequests\ApprovalRequestResource;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;

use function Livewire\invade;

/**
 * A porta de entrada da aprovação em dois passos para o aplicativo.
 *
 * DECLARAR uma ação que exige aprovação num resource novo (3 passos):
 *
 *   1. a ação: uma classe que estende ApprovableAction (chave, model alvo,
 *      guardas, antes/depois e o execute());
 *   2. o registro, no provider do aplicativo:
 *        Approvals::register(CancelOrder::class);
 *   3. a Action do Filament passa por gate() — o resto da Action continua
 *      igual (rótulo, confirmação, `before()` com as guardas):
 *        Approvals::gate(Action::make('cancel')->...->action(fn ... => ...), CancelOrder::class)
 *
 * E liga na config: ADMIN_APPROVALS_ACTIONS=orders.cancel (ou, se a ação
 * exige aprovação sempre, ApprovableAction::alwaysRequiresApproval()).
 * Desligada, a Action executa como sempre; ligada, pede o motivo (os campos
 * que a Action já tinha continuam) e cria o pedido, que outra pessoa aprova
 * na tela "Aprovações" — a notificação traz o link do pedido.
 */
final class Approvals
{
    /**
     * @param  ApprovableAction|class-string<ApprovableAction>  $action
     */
    public static function register(ApprovableAction|string $action): ApprovableAction
    {
        return app(ApprovalRegistry::class)->register($action);
    }

    public static function requires(string $key): bool
    {
        return app(ApprovalService::class)->requires($key);
    }

    /**
     * Com a aprovação ligada para a ação, a Action deixa de executar: pede o
     * motivo e cria o pedido pendente (as guardas `before()` da Action
     * continuam valendo antes disso). Desligada, devolve a Action intacta.
     *
     * @param  ApprovableAction|class-string<ApprovableAction>  $approvable
     */
    public static function gate(Action $action, ApprovableAction|string $approvable): Action
    {
        $registry = app(ApprovalRegistry::class);
        $instance = is_string($approvable) ? ($registry->all()[self::keyOf($approvable)] ?? app($approvable)) : $approvable;
        $key = $instance->key();

        if (! self::requires($key)) {
            return $action;
        }

        // Os campos que a Action já pedia continuam (viram os dados do
        // pedido); o motivo entra no fim.
        $existing = invade($action)->schema;
        $reason = Textarea::make('approval_reason')
            ->label(__('admin.approvals.reason'))
            ->helperText(__('admin.approvals.reason_hint'))
            ->required()
            ->maxLength(1000);

        return $action
            ->schema($existing instanceof Closure
                ? fn (Action $action): array => [...(array) $action->evaluate($existing), $reason]
                : [...(array) $existing, $reason])
            ->modalSubmitActionLabel(__('admin.approvals.request_submit'))
            ->action(function (Model $record, array $data, Action $action) use ($key): void {
                $reason = (string) ($data['approval_reason'] ?? '');
                unset($data['approval_reason']);

                try {
                    $request = app(ApprovalService::class)->request($key, $record, $data, $reason, auth()->user());
                } catch (RecordedDenial $denial) {
                    AdminAudit::notifyRecorded($denial, __('admin.approvals.denied_title'));

                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title(__('admin.approvals.requested'))
                    ->body(__('admin.approvals.requested_body'))
                    ->actions([
                        Action::make('viewApprovalRequest')
                            ->label(__('admin.approvals.view_request'))
                            ->url(ApprovalRequestResource::getUrl('view', ['record' => $request])),
                    ])
                    ->send();
            });
    }

    /**
     * @param  class-string<ApprovableAction>  $class
     */
    private static function keyOf(string $class): string
    {
        foreach (app(ApprovalRegistry::class)->all() as $key => $action) {
            if ($action instanceof $class) {
                return $key;
            }
        }

        return '';
    }
}
