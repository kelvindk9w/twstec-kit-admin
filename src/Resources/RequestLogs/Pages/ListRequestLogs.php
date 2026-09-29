<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\RequestLogs\Pages;

use Twstec\Kit\Admin\Resources\RequestLogs\RequestLogResource;
use Twstec\Kit\Admin\Support\BaseListRecords;

final class ListRequestLogs extends BaseListRecords
{
    protected static string $resource = RequestLogResource::class;
}
