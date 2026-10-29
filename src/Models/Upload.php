<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Database\Factories\UploadFactory;
use Mozex\Campaigns\Enums\UploadPurpose;

/**
 * A file an agent sends through a one-time upload link: an email, a zip of
 * one, an image or a list of contacts. Until the file arrives, expires_at
 * ends the link (LINK_MINUTES); once it arrives, it ends the file's stay
 * (KEEP_HOURS), and the tool that uses it claims it once.
 *
 * @property int $id
 * @property string $uuid
 * @property UploadPurpose $purpose
 * @property ?string $disk
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

    /** How long an upload link takes a file. */
    public const LINK_MINUTES = 15;

    /** How long a file that arrived waits for its tool. */
    public const KEEP_HOURS = 24;

    protected $table = 'campaign_uploads';

    protected $fillable = [
        'purpose',
        'disk',
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
     * @return array{purpose: 'Mozex\Campaigns\Enums\UploadPurpose', size: 'integer', expires_at: 'datetime', received_at: 'datetime', claimed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'purpose' => UploadPurpose::class,
            'size' => 'integer',
            'expires_at' => 'datetime',
            'received_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /**
     * The upload a link's id names. The id is checked first: PostgreSQL's
     * uuid column throws on a malformed one.
     *
     * @throws CampaignsException
     */
    public static function forId(string $uuid): self
    {
        $upload = Str::isUuid($uuid) ? static::query()->where('uuid', strtolower($uuid))->first() : null;

        return $upload ?? throw CampaignsException::uploadNotFound();
    }

    /**
     * The file's bytes.
     *
     * @throws CampaignsException when no file arrived or it is gone
     */
    public function contents(): string
    {
        $contents = $this->disk === null || $this->path === null
            ? null
            : Storage::disk($this->disk)->get($this->path);

        if ($contents === null) {
            throw CampaignsException::uploadUnavailable('its file is gone');
        }

        return $contents;
    }

    public function deleteFile(): void
    {
        if ($this->disk !== null && $this->path !== null) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
