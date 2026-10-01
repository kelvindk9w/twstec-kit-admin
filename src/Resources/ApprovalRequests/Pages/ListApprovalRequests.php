<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\ApprovalRequests\Pages;

use Twstec\Kit\Admin\Resources\ApprovalRequests\ApprovalRequestResource;
use Twstec\Kit\Admin\Support\BaseListRecords;

final class ListApprovalRequests extends BaseListRecords
{
    protected static string $resource = ApprovalRequestResource::class;
}
