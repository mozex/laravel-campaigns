<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\AssetFactory;

/**
 * An image in the app's asset library, shared by every template and
 * campaign. Delivered mail keeps loading it, so it is never deleted.
 *
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string $url
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property ?int $width
 * @property ?int $height
 * @property ?string $author
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(AssetFactory::class)]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'campaign_assets';

    protected $fillable = [
        'disk',
        'path',
        'url',
        'name',
        'mime',
        'size',
        'width',
        'height',
        'author',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{size: 'integer', width: 'integer', height: 'integer'}
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
