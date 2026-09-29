<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Auth;

use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Enums\VerificationResult;
use Twstec\Kit\Auth\Exceptions\TwoFactorLockedException;
use Twstec\Kit\Auth\Services\TwoFactorLogin;

/**
 * Segundo fator do login do /admin, plugado no mecanismo de MFA do Filament 5
 * — mas com o MOTOR do kit.
 *
 * O Filament 5 traz MFA nativo por e-mail (EmailAuthentication) e por app. O
 * que aproveitamos dele é a ORQUESTRAÇÃO do login: senha certa não autentica,
 * a página troca para o formulário do código, e só depois do código as
 * credenciais são conferidas de novo e a sessão nasce (com "lembrar de mim" e
 * regeneração do ID). O provedor de e-mail nativo não serve como está:
 *   - guarda o código na SESSÃO e não limita tentativas por código;
 *   - envia uma notificação própria, fora do layout, do idioma do
 *     destinatário e da fila criptografada dos e-mails do kit;
 *   - tem preferência própria (`has_email_authentication`) e ações de ligar e
 *     desligar que não pedem senha de transação.
 *
 * Então este provedor implementa o contrato do Filament sobre o
 * TwoFactorLogin: MESMA preferência do painel do cliente
 * (`two_factor_enabled_at`), mesmo código (VerificationCodes, finalidade
 * login), mesmo e-mail, mesmos limites por código, conta e IP. Uma pessoa, um
 * segundo fator — entre ela pelo /login ou pelo /admin/login.
 *
 * Ligar/desligar pelo /admin fica na página de perfil (Pages\Profile), com a
 * confirmação sensível do kit; por isso getManagementSchemaComponents() não
 * oferece nada.
 */
final class EmailCodeAuthentication implements HasBeforeChallengeHook, MultiFactorAuthenticationProvider
{
    public function __construct(
        private readonly TwoFactorLogin $twoFactor,
    ) {}

    public static function make(): self
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return 'kit_email_code';
    }

    public function getLoginFormLabel(): string
    {
        return __('auth.two_factor.title');
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user instanceof AuthUser && $this->twoFactor->enabledFor($user);
    }

    /**
     * Chamado pelo Filament quando a senha confere e o formulário do código
     * vai aparecer: envia o código (respeitando o intervalo de reenvio).
     */
    public function beforeChallenge(Authenticatable $user): void
    {
        if ($user instanceof AuthUser) {
            $this->twoFactor->sendCode($user);
        }
    }

    /**
     * @return array<never>
     */
    public function getManagementSchemaComponents(): array
    {
        return [];
    }

    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return [
            OneTimeCodeInput::make('code')
                ->label(__('auth.two_factor.code_label'))
                ->validationAttribute(__('auth.two_factor.code_label'))
                ->belowContent(Action::make('resend')
                    ->label(__('auth.two_factor.resend'))
                    ->link()
                    ->action(function () use ($user): void {
                        $this->resend($user);
                    }))
                ->required()
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                    $message = $this->check($user, $value);

                    if ($message !== null) {
                        $fail($message);
                    }
                }),
        ];
    }

    /**
     * Confere o código; devolve a mensagem de recusa ou null quando está certo.
     */
    private function check(Authenticatable $user, mixed $value): ?string
    {
        if (! $user instanceof AuthUser || ! is_string($value)) {
            return __('auth.two_factor.invalid');
        }

        if (! $user->isActive()) {
            return __('auth.account_inactive');
        }

        try {
            $result = $this->twoFactor->verify($user, $value, request()->ip());
        } catch (TwoFactorLockedException $exception) {
            return $exception->userMessage();
        }

        return match ($result) {
            VerificationResult::Valid => null,
            VerificationResult::Invalid => __('auth.two_factor.invalid'),
            VerificationResult::Expired => __('auth.two_factor.expired'),
        };
    }

    private function resend(Authenticatable $user): void
    {
        if (! $user instanceof AuthUser) {
            return;
        }

        try {
            $this->twoFactor->ensureNotLocked($user, request()->ip());
        } catch (TwoFactorLockedException $exception) {
            Notification::make()->title($exception->userMessage())->danger()->send();

            return;
        }

        $remaining = $this->twoFactor->sendCode($user);

        if ($remaining > 0) {
            Notification::make()
                ->title(__('auth.two_factor.resend_cooldown', ['seconds' => $remaining]))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title(__('auth.two_factor.resent'))->success()->send();
    }
}
