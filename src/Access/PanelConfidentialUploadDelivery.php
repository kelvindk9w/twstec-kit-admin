<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Access;

use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Admin\Authorization\AdminPermissions;
use Twstec\Kit\Admin\Resources\Uploads\UploadResource;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A entrega de um upload CONFIDENCIAL cuja URL foi gerada no /admin confere,
 * de novo, a MESMA permissão que a ação "abrir confidencial" exigiu na
 * geração (`uploads.view_confidential`) — não só a entrada no painel. Quem
 * perdeu a permissão (papel trocado para auditor, conta bloqueada, `is_admin`
 * retirado) dentro da validade da URL não abre mais o documento.
 *
 * Registrado pelo AdminServiceProvider só quando o twstec/kit-uploads está
 * instalado. A classe NÃO implementa o contrato do uploads
 * (Confidential\Contracts\AuthorizesSystemConfidentialDelivery): sem o módulo,
 * carregá-la (um `class_exists`, a descoberta de classes) derrubaria o
 * aplicativo com "interface não encontrada". Quem implementa o contrato é o
 * adaptador criado no AdminServiceProvider, só com o módulo presente.
 */
final class PanelConfidentialUploadDelivery
{
    public function allows(AuthUser&Model $actor): bool
    {
        return AdminPermissions::allows($actor, self::permission());
    }

    public static function permission(): string
    {
        $abilities = UploadResource::actionAbilities();

        return UploadResource::permissionKey().'.'.($abilities['openConfidential'] ?? 'view_confidential');
    }
}
