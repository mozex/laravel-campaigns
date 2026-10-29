<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\UploadFactory;

/**
 * A file an agent sends through a one-time upload link: an email, a zip of
 * one, an image or a list of contacts. It is kept until the tool that uses
 * it claims it, or it expires.
 *
 * @property int $id
 * @property string $uuid
 * @property string $purpose
 * @property ?string $path
 * @property ?int $size
 * @property ?string $mime
 * @property ?string $author
 * @property Carbon $expires_at
 * @property ?Carbon $received_at
 * @property ?Carbon $claimed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(UploadFactory::class)]
class Upload extends Model
{
    /** @use HasFactory<UploadFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'campaign_uploads';

    protected $fillable = [
        'purpose',
        'path',
        'size',
        'mime',
        'author',
        'expires_at',
        'received_at',
        'claimed_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{size: 'integer', expires_at: 'datetime', received_at: 'datetime', claimed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'datetime',
            'received_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }
}
