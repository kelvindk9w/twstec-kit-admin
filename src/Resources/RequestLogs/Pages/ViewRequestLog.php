<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\RequestLogs\Pages;

use Filament\Resources\Pages\ViewRecord;
use Twstec\Kit\Admin\Resources\RequestLogs\RequestLogResource;

final class ViewRequestLog extends ViewRecord
{
    protected static string $resource = RequestLogResource::class;
}
