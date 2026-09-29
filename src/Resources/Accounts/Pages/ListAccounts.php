<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Accounts\Pages;

use Twstec\Kit\Admin\Resources\Accounts\AccountResource;
use Twstec\Kit\Admin\Support\BaseListRecords;

final class ListAccounts extends BaseListRecords
{
    protected static string $resource = AccountResource::class;
}
