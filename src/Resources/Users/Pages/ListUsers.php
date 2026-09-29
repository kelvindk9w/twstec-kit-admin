<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Users\Pages;

use Filament\Actions\CreateAction;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\BaseListRecords;

final class ListUsers extends BaseListRecords
{
    protected static string $resource = UserResource::class;

    protected function getResourceHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('admin.users.create')),
        ];
    }
}
