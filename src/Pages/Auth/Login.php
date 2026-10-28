<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Pages\Auth;

use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Twstec\Kit\Auth\Actions\AttemptLogin;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Services\TwoFactorLogin;
use Twstec\Kit\Auth\Support\LoginPrefill;

/**
 * Login do super admin (/admin). Quando uma extensão sugere credenciais
 * (ponto de extensão Twstec\Kit\Auth\Contracts\LoginPrefillProvider, superfície
 * `admin`), o formulário nasce preenchido: basta clicar em entrar. Sem
 * extensão, nasce vazio.
 *
 * Quem sugere decide também QUANDO. A demonstração do kit, que sugere as
 * credenciais do admin demo, nunca o faz em produção sem opt-out declarado:
 * entregar `admin@tws.dev` com a senha pública do .env.example já digitada no
 * login do super admin é a forma mais curta de perder a instalação.
 *
 * SEGUNDO FATOR: com a verificação em duas etapas ligada na conta, o Filament
 * troca o formulário pelo do código (provedor Twstec\Kit\Admin\Auth\
 * EmailCodeAuthentication, registrado pelo AdminPlugin). Aqui o kit
 * acrescenta ao fluxo nativo o que o painel do cliente já tem:
 *   - VALIDADE do estado intermediário (AUTH_TWO_FACTOR_CHALLENGE_TTL_MINUTES):
 *     vencida, o código morre e a página de login recomeça, com o aviso;
 *   - VOLTAR: desiste do código, invalida o código enviado e recomeça a
 *     página de login.
 *
 * BLOQUEIO POR TENTATIVAS: o limite do próprio Filament conta pelo IP exato
 * — com IPv6, trocar de endereço dentro do próprio /64 (ou usar muitos IPs)
 * dava tentativas sem fim contra a senha de um admin. Por cima dele, este
 * login aplica a regra do login do aplicativo (Actions\AttemptLogin do
 * twstec/kit-auth), com os MESMOS contadores: e-mail + cliente
 * (ClientBucket, IPv6 pelo prefixo) e por CONTA, de qualquer origem — quem
 * testa senhas não ganha orçamento novo alternando entre /login e
 * /admin/login. Mesma mensagem genérica de bloqueio; senha certa zera os
 * dois.
 */
class Login extends BaseLogin
{
    /** Quando o formulário do código apareceu (timestamp) — para a validade. */
    #[Locked]
    public ?int $multiFactorChallengeStartedAt = null;

    public function mount(): void
    {
        parent::mount();

        $prefill = LoginPrefill::for('admin');

        if ($prefill !== null) {
            $this->form->fill([
                'email' => $prefill->email,
                'password' => $prefill->password,
                'remember' => true,
            ]);
        }
    }

    public function authenticate(): ?LoginResponse
    {
        if (filled($this->userUndertakingMultiFactorAuthentication) && $this->multiFactorChallengeExpired()) {
            Notification::make()
                ->danger()
                ->title(__('auth.two_factor.challenge_expired'))
                ->send();

            $this->restartLogin();

            return null;
        }

        $email = $this->attemptedEmail();
        $passwordStep = blank($this->userUndertakingMultiFactorAuthentication);

        // No passo da senha (o do código tem o limite próprio do segundo
        // fator): bloqueado, nem confere a senha.
        if ($passwordStep) {
            app(AttemptLogin::class)->ensureIsNotRateLimited(request(), $email, 'data.email');
        }

        $response = parent::authenticate();

        // Senha aceita = entrou ou está no passo do código. Resposta nula SEM
        // código pendente é o limite do próprio Filament recusando antes de
        // conferir a senha: aí nada é zerado.
        if ($response !== null || filled($this->userUndertakingMultiFactorAuthentication)) {
            app(AttemptLogin::class)->clearFailedAttempts(request(), $email);
        }

        if (blank($this->userUndertakingMultiFactorAuthentication)) {
            $this->multiFactorChallengeStartedAt = null;
        } elseif ($this->multiFactorChallengeStartedAt === null) {
            $this->multiFactorChallengeStartedAt = now()->getTimestamp();
        }

        return $response;
    }

    /**
     * Senha errada, conta inexistente ou conta que não entra no painel: conta
     * nos contadores do login do aplicativo antes da recusa do Filament.
     */
    protected function throwFailureValidationException(): never
    {
        app(AttemptLogin::class)->recordFailedAttempt(request(), $this->attemptedEmail());

        parent::throwFailureValidationException();
    }

    private function attemptedEmail(): string
    {
        $email = $this->data['email'] ?? null;

        return is_string($email) ? $email : '';
    }

    /**
     * Desiste do segundo passo: o código enviado deixa de valer e a tela
     * volta ao formulário de senha.
     */
    public function cancelMultiFactorChallenge(): void
    {
        $this->restartLogin();
    }

    /**
     * Encerra o estado intermediário e recarrega a página de login do zero.
     *
     * Recarregar (e não só esconder o formulário do código) é de propósito:
     * o Filament não prevê voltar do código para a senha no mesmo componente
     * — ao reaparecer, o formulário do código vinha sem o campo. Página nova,
     * componente novo, nenhum resto do desafio anterior.
     */
    private function restartLogin(): void
    {
        $user = $this->getUserUndertakingMultiFactorAuthentication();

        if ($user instanceof AuthUser) {
            app(TwoFactorLogin::class)->cancel($user);
        }

        $this->userUndertakingMultiFactorAuthentication = null;
        $this->multiFactorChallengeStartedAt = null;

        $this->redirect(Filament::getLoginUrl());
    }

    /**
     * @return array<Action>
     */
    protected function getMultiFactorChallengeFormActions(): array
    {
        return [
            ...parent::getMultiFactorChallengeFormActions(),
            Action::make('cancelMultiFactorChallenge')
                ->label(__('auth.two_factor.cancel'))
                ->link()
                ->color('gray')
                ->action(fn () => $this->cancelMultiFactorChallenge()),
        ];
    }

    private function multiFactorChallengeExpired(): bool
    {
        if ($this->multiFactorChallengeStartedAt === null) {
            return false;
        }

        $ttlSeconds = app(TwoFactorLogin::class)->challengeTtlMinutes() * 60;

        return now()->getTimestamp() - $this->multiFactorChallengeStartedAt >= $ttlSeconds;
    }
}
