<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Pages;

use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Resources\Users\Concerns\AssignsAdminRole;
use Twstec\Kit\Admin\Resources\Users\Support\UserAdminGuard;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\AvatarUpload;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Edição de usuário pelo super admin.
 *
 * Guardas de SERVIDOR (UserAdminGuard) antes de gravar: conta protegida é
 * intocável, o admin não se bloqueia, o último admin ativo não perde a
 * flag nem o acesso e ninguém edita quem tem papel acima do seu (a tela nem
 * abre — UserResource::getAuthorizationResponse) — esconder o botão não é
 * proteção. E-mail trocado volta a não verificado; e-mail ou senha trocados
 * renovam o `remember_token`.
 *
 * `status`/`is_admin` gravados por forceFill (nunca mass assignment). Tirar
 * a flag de acesso tira também o papel (`admin_role`): a pessoa não volta
 * ao painel com o papel antigo quando alguém lhe devolver a entrada. O papel
 * em si muda só pela ação sensível "Alterar papel" (AssignsAdminRole).
 */
final class EditUser extends EditRecord
{
    use AssignsAdminRole;

    protected static string $resource = UserResource::class;

    /**
     * Teto de largura do formulário — mesma medida das demais telas de
     * edição do painel (crítica de design: formulário sem teto).
     */
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::FourExtraLarge;
    }

    public function getTitle(): string
    {
        return __('admin.users.edit');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('admin.users.updated_success');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->assignRoleAction(),
            UserResource::deleteAction(),
        ];
    }

    /**
     * Preenche o campo de foto com o caminho do avatar atual, para o
     * formulário abrir mostrando a imagem que já está lá.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Model&AuthUser $record */
        $record = $this->getRecord();

        $data['avatar'] = AvatarUpload::stateFor($record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Model&AuthUser $record */
        if ($motivo = UserAdminGuard::updateDenial($record, $data, auth()->user())) {
            // Recusa registrada na trilha (`user.updated`, denied) e mostrada.
            AdminAudit::denied($motivo, $record, 'updated', __('admin.users.action_denied'));

            throw new Halt;
        }

        // Foto: só um upload DESTA conta (ou enviado agora, neste formulário)
        // vira a foto dela — ver AvatarUpload::denialFor().
        if ($motivo = AvatarUpload::denialFor($record, $data['avatar'] ?? null)) {
            AdminAudit::denied($motivo, $record, 'updated', __('admin.users.action_denied'));

            throw new Halt;
        }

        $atributos = [
            'name' => $data['name'],
            'email' => $data['email'],
            'status' => $data['status'],
            // Campo desabilitado (quem edita não atribui papel) não vem no
            // formulário: a flag fica como está.
            'is_admin' => array_key_exists('is_admin', $data) ? (bool) $data['is_admin'] : (bool) $record->getAttribute('is_admin'),
        ];

        if (! $atributos['is_admin']) {
            $atributos[AdminPermissions::COLUMN] = null;
        }

        // Senha em branco = manter a atual (o campo já vem desidratado).
        if (filled($data['password'] ?? null)) {
            $atributos['password'] = $data['password'];
        }

        $record->forceFill($atributos);

        // Trocar o e-mail ou a senha de OUTRA pessoa pelo painel é o caminho
        // de tomada de conta: o e-mail novo volta a precisar de confirmação
        // (quem confirma é o dono da caixa, não o operador) e o
        // `remember_token` é renovado — o "lembrar de mim" antigo deixa de
        // valer, como na redefinição de senha do twstec/kit-auth
        // (ResetPassword). As sessões do /admin dessa pessoa caem na troca de
        // senha (AuthenticateSession do painel confere o hash).
        if ($record->isDirty('email')) {
            $record->forceFill(['email_verified_at' => null]);
        }

        if ($record->isDirty(['email', 'password'])) {
            $record->forceFill(['remember_token' => Str::random(60)]);
        }

        $record->save();

        AvatarUpload::applyTo($record, $data['avatar'] ?? null);

        return $record;
    }
}
