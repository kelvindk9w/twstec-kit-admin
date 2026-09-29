<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Ação de SUPORTE: marcar o e-mail de uma conta como verificado.
 *
 * Caso de uso: a pessoa não recebe o e-mail de confirmação (filtro de spam,
 * caixa corporativa que descarta, endereço digitado certo mas servidor
 * recusando) e o suporte confirma a identidade por outro canal. Sem esta
 * ação, a saída era shell no servidor.
 *
 * A MESMA ação serve a listagem (tabela e cards) e o detalhe do usuário —
 * uma definição só, para as regras não divergirem entre as telas:
 * - aparece só para conta AINDA não verificada (User::hasVerifiedEmail, a
 *   mesma pergunta que o middleware do painel faz);
 * - some e é recusada no servidor para conta protegida (UserAdminGuard);
 * - pede confirmação;
 * - dispara o evento `Verified` do framework, como o link do e-mail faz;
 * - fica registrada na trilha de auditoria (`audit_events`) como
 *   `user.email_marked_verified`, com o antes/depois da data — pela captura
 *   central do /admin (AdminAudit), não por uma chamada daqui. A recusa
 *   (conta protegida) também fica, como `denied`.
 *
 * No modo cards vira ícone verde com o nome no hover (CardActions, pelo
 * NAME desta ação).
 */
final class MarkEmailVerifiedAction
{
    public const NAME = 'markEmailVerified';

    /**
     * Verbo e nome estável da ação na trilha de auditoria (o verbo vem do
     * mapa AdminAudit::VERBS pelo NAME desta ação).
     */
    public const VERB = 'email_marked_verified';

    public const AUDIT_ACTION = 'user.'.self::VERB;

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label(__('admin.users.mark_email_verified'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Model&AuthUser $record): bool => self::isAvailableFor($record))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedCheckBadge)
            ->modalHeading(__('admin.users.mark_email_verified_heading'))
            ->modalDescription(fn (Model&AuthUser $record): string => __('admin.users.mark_email_verified_warning', ['email' => $record->email]))
            ->modalSubmitActionLabel(__('admin.users.mark_email_verified_confirm'))
            ->action(function (Model&AuthUser $record): void {
                // Guarda de SERVIDOR: esconder o botão não é proteção.
                if ($motivo = UserAdminGuard::verifyEmailDenial($record)) {
                    AdminAudit::denied($motivo, $record, self::VERB, __('admin.users.action_denied'));

                    return;
                }

                // Outra aba/outro admin pode ter confirmado antes: nada a
                // fazer e nada a registrar.
                if ($record->hasVerifiedEmail()) {
                    Notification::make()->info()->title(__('admin.users.email_already_verified'))->send();

                    return;
                }

                $record->markEmailAsVerified();

                event(new Verified($record));

                Notification::make()->success()->title(__('admin.users.email_marked_verified'))->send();
            });
    }

    /**
     * A ação se aplica a este registro? (Visibilidade = a mesma regra que a
     * execução reconfere no servidor.)
     */
    public static function isAvailableFor(Model&AuthUser $record): bool
    {
        return ! $record->hasVerifiedEmail() && UserAdminGuard::verifyEmailDenial($record) === null;
    }
}
