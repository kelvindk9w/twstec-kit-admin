<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\AvatarUpload;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Auth\Services\TwoFactorLogin;

/**
 * Perfil do super admin (/admin).
 *
 * - FOTO: editável — sobe pela função global de upload do kit
 *   (AvatarUpload → SecureUploadService) e aparece na hora no avatar do
 *   cabeçalho, porque o InitialsAvatarProvider lê o mesmo vínculo.
 * - NOME: editável e funcional (grava na conta logada).
 * - E-MAIL: read-only com nota explicativa — o e-mail de acesso não muda por
 *   esta tela (numa conta protegida, como a conta demo, mudá-lo quebraria o
 *   login dos próximos visitantes).
 * - SENHA: seção MONTADA mas sem endpoint — campo de senha atual
 *   desabilitado (preview) com a nota "indisponível por esta tela". Nenhum
 *   campo da seção é desidratado: nada aqui pode derrubar o acesso de uma
 *   conta protegida.
 * - VERIFICAÇÃO EM DUAS ETAPAS: a MESMA preferência do painel do cliente
 *   (TwoFactorLogin), com a MESMA regra — ligar e desligar pedem senha de
 *   transação + código por e-mail (SensitiveActionService) e o token emitido é
 *   consumido pelo próprio TwoFactorLogin. Em dois modais encadeados: senha →
 *   código. A conta protegida vê o motivo e o botão desabilitado.
 *
 * TRILHA DE AUDITORIA: salvar o perfil vira `user.updated` (nome mascarado)
 * e ligar/desligar vira `user.two_factor_enabled`/`user.two_factor_disabled`
 * — pela captura central do /admin (AdminAudit). Toda recusa (senha de
 * transação errada, código errado, conta protegida) fica registrada como
 * `denied` com o motivo: é o sinal de alguém tentando mexer no segundo fator
 * de uma sessão que não é a dele.
 */
final class Profile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'kit-admin::pages.profile';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();

        $this->form->fill([
            'name' => $user?->name,
            'email' => $user?->email,
            'avatar' => AvatarUpload::stateFor($user),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.profile.heading');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Foto de perfil: só com o twstec/kit-uploads.
                ...(AvatarUpload::available() ? [AvatarUpload::field()->label(__('panel.profile.avatar_heading'))] : []),
                TextInput::make('name')
                    ->label(__('panel.common.name'))
                    ->required()
                    ->maxLength(120),
                TextInput::make('email')
                    ->label(__('auth.ui.email'))
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('admin.profile.email_readonly_note')),
                Section::make(__('admin.profile.password_section'))
                    ->description(__('admin.profile.password_note'))
                    ->schema([
                        TextInput::make('current_password')
                            ->label(__('admin.profile.current_password'))
                            ->password()
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Passo 1: senha de transação → código por e-mail. Depois abre o passo 2
     * no lugar deste modal.
     */
    public function toggleTwoFactorAction(): Action
    {
        return Action::make('toggleTwoFactor')
            ->label(fn (): string => $this->twoFactorEnabled()
                ? __('panel.profile.two_factor_disable')
                : __('panel.profile.two_factor_enable'))
            ->color(fn (): string => $this->twoFactorEnabled() ? 'gray' : 'primary')
            ->disabled(fn (): bool => app(TwoFactorLogin::class)->blockedReason($this->user()) !== null)
            ->modalHeading(__('panel.sensitive.heading'))
            ->modalDescription(fn (): string => $this->twoFactorEnabled()
                ? __('panel.profile.two_factor_confirm_disable')
                : __('panel.profile.two_factor_confirm_enable'))
            ->schema([
                TextInput::make('transaction_password')
                    ->label(__('auth.ui.transaction_password_title'))
                    ->helperText(__('panel.sensitive.password_hint'))
                    ->password()
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('panel.sensitive.send_code'))
            ->action(function (array $data): void {
                $reason = app(TwoFactorLogin::class)->blockedReason($this->user());
                $verb = $this->twoFactorVerb(! $this->twoFactorEnabled());

                if ($reason !== null) {
                    $this->refuse($reason, $verb);
                }

                try {
                    app(SensitiveActionService::class)->sendCode($this->user(), (string) ($data['transaction_password'] ?? ''));
                } catch (ValidationException $exception) {
                    $this->refuse($this->firstMessage($exception), $verb);
                }

                $this->replaceMountedAction('confirmTwoFactor');
            });
    }

    /**
     * Passo 2: código por e-mail → token de ação sensível → liga/desliga.
     */
    public function confirmTwoFactorAction(): Action
    {
        return Action::make('confirmTwoFactor')
            ->modalHeading(__('panel.sensitive.heading'))
            ->modalDescription(__('panel.sensitive.code_hint'))
            ->schema([
                OneTimeCodeInput::make('code')
                    ->label(__('panel.sensitive.code'))
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('panel.sensitive.confirm'))
            ->action(function (array $data): void {
                $twoFactor = app(TwoFactorLogin::class);
                $user = $this->user();
                $enabling = ! $twoFactor->enabledFor($user);

                // A mesma Action liga e desliga: o nome na trilha depende do estado.
                AdminAudit::describeAs($this->twoFactorVerb($enabling));

                try {
                    $issued = app(SensitiveActionService::class)->confirmCode($user, (string) ($data['code'] ?? ''));

                    $enabling
                        ? $twoFactor->enable($user, $issued['token'])
                        : $twoFactor->disable($user, $issued['token']);
                } catch (ValidationException $exception) {
                    $this->refuse($this->firstMessage($exception), $this->twoFactorVerb($enabling));
                }

                Notification::make()
                    ->success()
                    ->title(__($enabling ? 'auth.two_factor.enabled' : 'auth.two_factor.disabled'))
                    ->send();
            });
    }

    public function twoFactorAvailable(): bool
    {
        return TwoFactorLogin::available();
    }

    public function twoFactorEnabled(): bool
    {
        return app(TwoFactorLogin::class)->enabledFor($this->user());
    }

    public function twoFactorBlockedReason(): ?string
    {
        return app(TwoFactorLogin::class)->blockedReason($this->user());
    }

    public function save(): void
    {
        /** @var array{name: string, avatar?: mixed} $state */
        $state = $this->form->getState();

        /** @var Model&AuthUser $user */
        $user = auth()->user();

        // Foto: só um upload da própria conta (ou enviado agora, neste
        // formulário) — ver AvatarUpload::denialFor().
        if ($motivo = AvatarUpload::denialFor($user, $state['avatar'] ?? null)) {
            AdminAudit::denied($motivo, $user, 'updated');

            return;
        }

        // Uma transação: a linha da trilha (user.updated) e a mudança entram
        // juntas ou não entram (ver AuditTrail — falha fechada).
        DB::transaction(function () use ($user, $state): void {
            $user->forceFill(['name' => $state['name']])->save();

            AvatarUpload::applyTo($user, $state['avatar'] ?? null);
        });

        Notification::make()
            ->success()
            ->title(__('admin.profile.saved'))
            ->send();
    }

    /**
     * Recusa com o motivo na tela, registra a tentativa na trilha e mantém o
     * modal aberto.
     */
    private function refuse(string $message, string $verb): never
    {
        AdminAudit::denied($message, $this->user(), $verb);

        // Mesmo efeito de $action->halt(), escrito como `throw` para o
        // retorno `never` ficar explícito.
        throw new Halt;
    }

    private function twoFactorVerb(bool $enabling): string
    {
        return $enabling ? 'two_factor_enabled' : 'two_factor_disabled';
    }

    private function firstMessage(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }

    private function user(): Model&AuthUser
    {
        /** @var Model&AuthUser */
        return auth()->user();
    }
}
