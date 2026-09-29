<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Pages;

use Filament\Resources\Pages\ViewRecord;
use Twstec\Kit\Admin\Resources\Users\Support\MarkEmailVerifiedAction;
use Twstec\Kit\Admin\Resources\Users\UserResource;

final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * No detalhe, a mesma ação de suporte da listagem (uma definição só —
     * ver MarkEmailVerifiedAction).
     */
    protected function getHeaderActions(): array
    {
        return [
            MarkEmailVerifiedAction::make(),
        ];
    }
}
