<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Resources\Projects\Pages;

use Twstec\Kit\Admin\Resources\Projects\ProjectResource;
use Twstec\Kit\Admin\Support\BaseListRecords;

final class ListProjects extends BaseListRecords
{
    protected static string $resource = ProjectResource::class;
}
