<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

/**
 * Uploads de foto enviados NESTA requisição pelo campo de foto do /admin
 * (AvatarUpload::field).
 *
 * É o que distingue "o operador acabou de escolher esta foto no formulário"
 * de "o estado do formulário foi adulterado para apontar para o upload de
 * outra pessoa": o uuid só entra aqui quando o próprio servidor gravou o
 * arquivo, pela função global de upload, na mesma requisição.
 *
 * Registrado com escopo de requisição (`scoped`): não sobrevive de uma
 * requisição para outra, nem num processo longo (Octane, fila).
 */
final class FreshAvatarUploads
{
    /**
     * @var array<string, true>
     */
    private array $uuids = [];

    public function remember(string $uuid): void
    {
        $this->uuids[strtolower($uuid)] = true;
    }

    public function has(string $uuid): bool
    {
        return isset($this->uuids[strtolower($uuid)]);
    }
}
