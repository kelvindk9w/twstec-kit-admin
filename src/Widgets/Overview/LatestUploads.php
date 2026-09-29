<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Widgets\Overview;

use BackedEnum;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Admin\Resources\Uploads\UploadResource;
use Twstec\Kit\Admin\Widgets\Support\BaseLatestRecordsWidget;
use Twstec\Kit\Admin\Widgets\Support\MetricFormat;
use Twstec\Kit\Admin\Widgets\Support\Period;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * Últimos arquivos aceitos pela função global de upload (SecureUploadService): nome
 * sanitizado, tipo REAL (magic bytes, nunca o declarado) e tamanho.
 */
final class LatestUploads extends BaseLatestRecordsWidget
{
    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 2, 'xl' => 6];

    /**
     * @return Builder<Upload>
     */
    protected function latestQuery(Period $period): Builder
    {
        return Upload::query();
    }

    protected function latestHeading(): string
    {
        return __('admin.dashboards.overview.latest_uploads');
    }

    protected function latestIcon(): string|BackedEnum
    {
        return Heroicon::OutlinedCloudArrowUp;
    }

    protected function latestUrl(): ?string
    {
        return UploadResource::getUrl();
    }

    /**
     * @return array<TextColumn>
     */
    protected function latestColumns(): array
    {
        return [
            TextColumn::make('original_name')
                ->label(__('admin.dashboards.overview.upload_name'))
                ->weight(FontWeight::SemiBold)
                ->limit(28),

            TextColumn::make('mime')
                ->label(__('admin.dashboards.overview.upload_type'))
                ->badge()
                ->color('gray'),

            TextColumn::make('size')
                ->label(__('admin.dashboards.overview.upload_size'))
                ->formatStateUsing(fn ($state): string => MetricFormat::Bytes->display((float) $state))
                ->alignEnd(),

            $this->whenColumn(),
        ];
    }
}
