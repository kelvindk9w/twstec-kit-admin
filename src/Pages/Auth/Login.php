<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Pages\Auth;

use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
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

        $response = parent::authenticate();

        if (blank($this->userUndertakingMultiFactorAuthentication)) {
            $this->multiFactorChallengeStartedAt = null;
        } elseif ($this->multiFactorChallengeStartedAt === null) {
            $this->multiFactorChallengeStartedAt = now()->getTimestamp();
        }

        return $response;
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
