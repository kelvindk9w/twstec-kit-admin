<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\ApiKeys\Pages;

use Twstec\Kit\Admin\Resources\ApiKeys\ApiKeyResource;
use Twstec\Kit\Admin\Support\BaseListRecords;

final class ListApiKeys extends BaseListRecords
{
    protected static string $resource = ApiKeyResource::class;
}
