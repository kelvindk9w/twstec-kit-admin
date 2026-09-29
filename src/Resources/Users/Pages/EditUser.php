<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Pages;

use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Resources\Users\Support\UserAdminGuard;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\AvatarUpload;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Edição de usuário pelo super admin.
 *
 * Guardas de SERVIDOR (UserAdminGuard) antes de gravar: conta protegida é
 * intocável, o admin não se bloqueia e o último admin ativo não perde a
 * flag nem o acesso — esconder o botão não é proteção.
 *
 * `status`/`is_admin` gravados por forceFill (nunca mass assignment).
 */
final class EditUser extends EditRecord
{
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
            'is_admin' => (bool) ($data['is_admin'] ?? false),
        ];

        // Senha em branco = manter a atual (o campo já vem desidratado).
        if (filled($data['password'] ?? null)) {
            $atributos['password'] = $data['password'];
        }

        $record->forceFill($atributos)->save();

        AvatarUpload::applyTo($record, $data['avatar'] ?? null);

        return $record;
    }
}
