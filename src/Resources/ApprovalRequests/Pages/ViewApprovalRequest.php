<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\ApprovalRequests\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use SensitiveParameter;
use Twstec\Kit\Admin\Approvals\ApprovalService;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalMode;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Approvals\Models\ApprovalRequest;
use Twstec\Kit\Admin\Resources\ApprovalRequests\ApprovalRequestResource;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\Concerns\ConfirmsSensitiveActions;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;

/**
 * Detalhe de um pedido e as decisões sobre ele.
 *
 * - APROVAR: com a ação sensível exigida (padrão), em dois modais — senha de
 *   transação → código → token entregue ao ApprovalService, que consome. Sem
 *   ela (ADMIN_APPROVALS_SENSITIVE=false, só no modo quatro olhos), uma
 *   confirmação simples. O botão some para quem pediu (quatro olhos), mas a
 *   regra é do serviço.
 * - RECUSAR: com motivo.
 * - EXECUTAR (modo de um operador): depois da espera mínima.
 * - EXCLUIR (só pedido encerrado, `approvals.delete`): limpeza da tela; o
 *   histórico continua na trilha.
 *
 * Toda recusa do serviço já está na trilha quando chega aqui (RecordedDenial):
 * a tela só avisa.
 */
final class ViewApprovalRequest extends ViewRecord
{
    use ConfirmsSensitiveActions;

    protected static string $resource = ApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
            $this->executeAction(),
            $this->deleteAction(),
        ];
    }

    public function approveAction(): Action
    {
        $action = Action::make('approve')
            ->label(__('admin.approvals.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize(fn (): bool => ApprovalRequestResource::allows('approve'))
            ->visible(fn (): bool => $this->canApprove());

        if (! $this->sensitiveRequired()) {
            return $action
                ->requiresConfirmation()
                ->modalHeading(__('admin.approvals.approve_heading'))
                ->modalDescription(__('admin.approvals.approve_description'))
                ->action(fn () => $this->decideApproval(null));
        }

        return $action
            ->modalHeading(__('admin.approvals.approve_heading'))
            ->modalDescription(__('admin.approvals.approve_description'))
            ->schema([$this->sensitivePasswordField()])
            ->modalSubmitActionLabel(__('panel.sensitive.send_code'))
            ->action(function (array $data): void {
                $this->sendSensitiveCode($data['transaction_password'] ?? '', 'approved', $this->request());

                $this->replaceMountedAction('confirmApprove');
            });
    }

    public function confirmApproveAction(): Action
    {
        return Action::make('confirmApprove')
            ->modalHeading(__('panel.sensitive.heading'))
            ->modalDescription(__('panel.sensitive.code_hint'))
            ->schema([$this->sensitiveCodeField()])
            ->modalSubmitActionLabel(__('admin.approvals.approve'))
            ->action(function (array $data): void {
                $this->decideApproval($this->confirmSensitiveCode($data['code'] ?? '', 'approved', $this->request()));
            });
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('admin.approvals.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize(fn (): bool => ApprovalRequestResource::allows('reject'))
            ->visible(fn (): bool => $this->request()->status->isOpen())
            ->modalHeading(__('admin.approvals.reject_heading'))
            ->schema([
                Textarea::make('decision_reason')
                    ->label(__('admin.approvals.decision_reason'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                try {
                    app(ApprovalService::class)->reject($this->request(), auth()->user(), (string) ($data['decision_reason'] ?? ''));
                } catch (RecordedDenial $denial) {
                    $this->refuseRecorded($denial);
                }

                Notification::make()->success()->title(__('admin.approvals.rejected'))->send();
                $this->refreshRequest();
            });
    }

    public function executeAction(): Action
    {
        return Action::make('execute')
            ->label(__('admin.approvals.execute'))
            ->icon(Heroicon::OutlinedPlay)
            ->color('warning')
            ->authorize(fn (): bool => ApprovalRequestResource::allows('execute'))
            ->visible(fn (): bool => $this->request()->status === ApprovalStatus::Approved)
            ->requiresConfirmation()
            ->modalHeading(__('admin.approvals.execute_heading'))
            ->modalDescription(fn (): string => __('admin.approvals.execute_description', [
                'time' => $this->request()->executable_after?->setTimezone(platform()->displayTimezone)->format('d/m/Y H:i') ?? '—',
            ]))
            ->action(function (): void {
                try {
                    $request = app(ApprovalService::class)->execute($this->request(), auth()->user());
                } catch (RecordedDenial $denial) {
                    $this->refuseRecorded($denial);
                }

                $this->notifyOutcome($request);
            });
    }

    /**
     * Excluir pedido ENCERRADO (limpeza): o histórico fica na trilha.
     */
    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('admin.approvals.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->authorize(fn (): bool => ApprovalRequestResource::allows('delete'))
            ->visible(fn (): bool => ! $this->request()->status->isOpen())
            ->requiresConfirmation()
            ->modalHeading(__('admin.approvals.delete_heading'))
            ->modalDescription(__('admin.approvals.delete_description'))
            ->action(function (): void {
                try {
                    app(ApprovalService::class)->delete($this->request(), auth()->user());
                } catch (RecordedDenial $denial) {
                    $this->refuseRecorded($denial);
                }

                Notification::make()->success()->title(__('admin.approvals.deleted'))->send();

                $this->redirect(ApprovalRequestResource::getUrl('index'));
            });
    }

    private function decideApproval(#[SensitiveParameter] ?string $token): void
    {
        try {
            $request = app(ApprovalService::class)->approve($this->request(), auth()->user(), $token);
        } catch (RecordedDenial $denial) {
            $this->refuseRecorded($denial);
        }

        $this->notifyOutcome($request);
    }

    private function notifyOutcome(ApprovalRequest $request): void
    {
        $notification = match ($request->status) {
            ApprovalStatus::Executed => Notification::make()->success()->title(__('admin.approvals.executed')),
            ApprovalStatus::Approved => Notification::make()->success()->title(__('admin.approvals.approved_waiting')),
            // A falha da execução já está na trilha (`approval_request.failed`).
            default => Notification::make()->warning()->title(__('admin.approvals.execution_failed'))->body((string) $request->failure_reason),
        };

        $notification->send();
        $this->refreshRequest();
    }

    /**
     * O botão de aprovar: pendente, dentro da validade e — no modo quatro
     * olhos — não para quem pediu.
     */
    private function canApprove(): bool
    {
        $request = $this->request();

        if ($request->status !== ApprovalStatus::Pending || $request->isExpired()) {
            return false;
        }

        return ApprovalService::modeFor($request) === ApprovalMode::SingleOperator
            || (string) auth()->user()?->getAttribute('uuid') !== $request->requested_by_uuid;
    }

    private function sensitiveRequired(): bool
    {
        return ApprovalService::sensitiveRequired(ApprovalService::modeFor($this->request()));
    }

    private function refuseRecorded(RecordedDenial $denial): never
    {
        AdminAudit::notifyRecorded($denial, __('admin.approvals.denied_title'));
        $this->refreshRequest();

        throw new Halt;
    }

    private function refreshRequest(): void
    {
        $this->request()->refresh();
    }

    private function request(): ApprovalRequest
    {
        /** @var ApprovalRequest */
        return $this->getRecord();
    }
}
