<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mozex\Campaigns\Content\Tracking;
use Mozex\Campaigns\Database\Factories\CampaignLinkFactory;

/**
 * One address a revision's links lead to, which its tracked links redirect
 * to through the random key. The key is all a click's path carries, so the
 * redirect leads only where the email's own links led (no open redirect on
 * the app's host, and no app key involved), and a link keeps working after
 * its events are pruned, since rows are never deleted.
 *
 * @property int $id
 * @property int $revision_id
 * @property string $key
 * @property string $url
 * @property string $url_hash
 * @property Carbon $created_at
 * @property-read ?Revision $revision
 */
#[UseFactory(CampaignLinkFactory::class)]
class CampaignLink extends Model
{
    /** @use HasFactory<CampaignLinkFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const KEY_LENGTH = 22;

    /** Rows per insert: five values each, well under every database's limit on bound values. */
    protected const CHUNK = 100;

    protected $table = 'campaign_links';

    protected $fillable = [
        'revision_id',
        'key',
        'url',
        'url_hash',
    ];

    /**
     * @return array{revision_id: 'integer'}
     */
    protected function casts(): array
    {
        return ['revision_id' => 'integer'];
    }

    /**
     * The revision's tracked addresses' keys, by address.
     *
     * @return array<string, string>
     */
    public static function keysFor(Revision $revision): array
    {
        /** @var array<string, string> */
        return static::query()->where('revision_id', $revision->id)->pluck('key', 'url')->all();
    }

    /**
     * Makes a row for each address the revision's links lead to that has
     * none, and returns every key by address. A row another request made
     * meanwhile (a test email of the revision composed while its campaign
     * starts) is skipped by the unique pair rather than failing, and the rows
     * are read back with a locking read, since inside a transaction MySQL's
     * plain read sees its snapshot from before that row existed. Inserted in
     * chunks, a few statements for an email of hundreds of addresses in
     * twenty languages, while the freeze holds the campaign's row.
     *
     * @return array<string, string>
     */
    public static function writeFor(Revision $revision): array
    {
        $keys = static::keysFor($revision);
        $missing = [];

        foreach ($revision->contents()->pluck('html') as $html) {
            foreach (Tracking::urls((string) $html) as $url) {
                if (! isset($keys[$url])) {
                    $missing[$url] = [
                        'revision_id' => $revision->id,
                        'key' => Str::random(static::KEY_LENGTH),
                        'url' => $url,
                        'url_hash' => static::hash($url),
                        'created_at' => now(),
                    ];
                }
            }
        }

        if ($missing === []) {
            return $keys;
        }

        foreach (array_chunk(array_values($missing), static::CHUNK) as $rows) {
            static::query()->insertOrIgnore($rows);
        }

        /** @var array<string, string> */
        return static::query()->where('revision_id', $revision->id)->sharedLock()->pluck('key', 'url')->all();
    }

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    /** @return BelongsTo<Revision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }
}
