<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support\Concerns;

use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Services\SensitiveActionService;

/**
 * A AÇÃO SENSÍVEL do kit (twstec/kit-auth) numa tela do /admin, em dois
 * modais encadeados — o mesmo fluxo de ligar o segundo fator no perfil:
 *
 *   1. senha de TRANSAÇÃO (hash separado da de login) → código por e-mail;
 *   2. código → TOKEN de uso único, entregue ao serviço que executa (ele o
 *      consome — AdminRoles, ApprovalService). Sem token válido, o serviço
 *      recusa: a tela não é a barreira.
 *
 * Toda recusa (senha errada, código errado, intervalo de reenvio) vai para a
 * trilha como `denied` com o motivo e mantém o modal aberto.
 */
trait ConfirmsSensitiveActions
{
    protected function sensitivePasswordField(): TextInput
    {
        return TextInput::make('transaction_password')
            ->label(__('auth.ui.transaction_password_title'))
            ->helperText(__('panel.sensitive.password_hint'))
            ->password()
            ->required();
    }

    protected function sensitiveCodeField(): OneTimeCodeInput
    {
        return OneTimeCodeInput::make('code')
            ->label(__('panel.sensitive.code'))
            ->required();
    }

    /**
     * Passo 1: confere a senha de transação e manda o código.
     */
    protected function sendSensitiveCode(#[SensitiveParameter] mixed $password, string $verb, ?Model $subject = null): void
    {
        try {
            app(SensitiveActionService::class)->sendCode($this->sensitiveActor(), (string) $password);
        } catch (ValidationException $exception) {
            $this->refuseSensitive($this->firstSensitiveMessage($exception), $verb, $subject);
        }
    }

    /**
     * Passo 2: confere o código e devolve o token de uso único.
     */
    protected function confirmSensitiveCode(#[SensitiveParameter] mixed $code, string $verb, ?Model $subject = null): string
    {
        try {
            return app(SensitiveActionService::class)->confirmCode($this->sensitiveActor(), (string) $code)['token'];
        } catch (ValidationException $exception) {
            $this->refuseSensitive($this->firstSensitiveMessage($exception), $verb, $subject);
        }
    }

    /**
     * Recusa registrada na trilha; o modal continua aberto.
     */
    private function refuseSensitive(string $message, string $verb, ?Model $subject): never
    {
        AdminAudit::denied($message, $subject ?? $this->sensitiveActor(), $verb);

        throw new Halt;
    }

    private function firstSensitiveMessage(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }

    private function sensitiveActor(): Model&AuthUser
    {
        /** @var Model&AuthUser */
        return auth()->user();
    }
}
