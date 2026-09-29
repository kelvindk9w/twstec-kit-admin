<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Support\AvatarUpload;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * Criação de usuário pelo super admin.
 *
 * `status` e `is_admin` NÃO são mass-assignable (a flag de admin
 * nunca entra por atribuição em massa) — por isso a criação é feita com
 * forceFill explícito em vez do create() padrão do Filament.
 */
final class CreateUser extends CreateRecord
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
        return __('admin.users.create');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admin.users.created_success');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        // A conta ainda não existe: só um upload enviado agora, neste
        // formulário, pode virar a foto dela — ver AvatarUpload::denialFor().
        // A recusa vem ANTES do INSERT: nada é gravado além da linha da
        // trilha (`user.created`, denied).
        if ($motivo = AvatarUpload::denialFor(null, $data['avatar'] ?? null)) {
            AdminAudit::denied($motivo, null, 'created', __('admin.users.action_denied'), subjectType: 'user');

            throw new Halt;
        }

        $user = UserModel::make();

        // O cast `hashed` do model cuida do Argon2id da senha.
        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => $data['status'],
            'is_admin' => (bool) ($data['is_admin'] ?? false),
            // Conta criada pelo admin já nasce com e-mail verificado: quem
            // cadastrou é o operador do painel, não um visitante anônimo.
            'email_verified_at' => now(),
        ])->save();

        // A foto entra depois do INSERT: o vínculo é uma FK e o upload já
        // foi persistido (e validado) pela função global — ver AvatarUpload.
        AvatarUpload::applyTo($user, $data['avatar'] ?? null);

        return $user;
    }
}
