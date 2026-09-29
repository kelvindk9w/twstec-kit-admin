<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Accounts\Pages;

use Filament\Resources\Pages\ViewRecord;
use Twstec\Kit\Admin\Resources\Accounts\AccountResource;

final class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;
}
