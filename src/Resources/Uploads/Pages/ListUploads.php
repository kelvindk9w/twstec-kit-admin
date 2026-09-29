<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Uploads\Pages;

use Twstec\Kit\Admin\Resources\Uploads\UploadResource;
use Twstec\Kit\Admin\Support\BaseListRecords;

final class ListUploads extends BaseListRecords
{
    protected static string $resource = UploadResource::class;
}
