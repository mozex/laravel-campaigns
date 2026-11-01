<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\Locales;
use Mozex\Campaigns\Database\Factories\RevisionFactory;

/**
 * One version of a template's or a campaign's email: a subject, preheader,
 * HTML and plain text per locale. A revision never changes once made, except
 * for the results of the checks run on it.
 *
 * @property int $id
 * @property string $uuid
 * @property string $revisable_type
 * @property int $revisable_id
 * @property int $number
 * @property ?string $note
 * @property ?string $author
 * @property ?array<string, mixed> $check_results
 * @property ?Carbon $checked_at
 * @property ?int $frozen_from_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, RevisionContent> $contents
 * @property-read Collection<int, CampaignLink> $links
 */
#[UseFactory(RevisionFactory::class)]
class Revision extends Model
{
    /** @use HasFactory<RevisionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'campaign_revisions';

    protected $fillable = [
        'revisable_type',
        'revisable_id',
        'number',
        'note',
        'author',
        'check_results',
        'checked_at',
        'frozen_from_id',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{revisable_id: 'integer', number: 'integer', check_results: 'array', checked_at: 'datetime', frozen_from_id: 'integer'}
     */
    protected function casts(): array
    {
        return [
            'revisable_id' => 'integer',
            'number' => 'integer',
            'check_results' => 'array',
            'checked_at' => 'datetime',
            'frozen_from_id' => 'integer',
        ];
    }

    /**
     * The revision an author made that this one was copied from when its
     * campaign started, for a copy with UTM tags added.
     *
     * @return BelongsTo<self, $this>
     */
    public function frozenFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'frozen_from_id');
    }

    /**
     * The revision as its author made it: this one, or for a copy a
     * campaign's start made, the one it was copied from.
     */
    public function authored(): self
    {
        return $this->frozen_from_id === null ? $this : ($this->frozenFrom()->first() ?? $this);
    }

    /** @return MorphTo<Model, $this> */
    public function revisable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<RevisionContent, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(RevisionContent::class);
    }

    /** @return HasMany<CampaignLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(CampaignLink::class);
    }

    /**
     * The content for a recipient's locale, however it is written (fr_CA,
     * fr-ca), else for its language alone (fr_CA gets fr), else another
     * version in that language (fr_CA gets fr_BE, pt gets pt_BR: closer than
     * the default), else the default locale's, which every revision holds.
     */
    public function contentFor(?string $locale): RevisionContent
    {
        $contents = $this->contents->sortBy('id');

        if ($locale !== null) {
            $wanted = Locales::normalize($locale);
            $language = Locales::language($wanted);

            $match = $contents->first(fn (RevisionContent $content): bool => Locales::normalize($content->locale) === $wanted)
                ?? $contents->first(fn (RevisionContent $content): bool => Locales::normalize($content->locale) === $language)
                ?? $contents->first(fn (RevisionContent $content): bool => Locales::language($content->locale) === $language);

            if ($match !== null) {
                return $match;
            }
        }

        $default = Locales::normalize(Locales::default());

        return $contents->first(fn (RevisionContent $content): bool => Locales::normalize($content->locale) === $default)
            ?? $contents->firstOrFail();
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->contents->pluck('locale')->sort()->values()->all();
    }

    /**
     * The revision's email as content for a new revision. A generated text is
     * left out, so the copy's is generated again and keeps its flag.
     *
     * @return list<EmailContent>
     */
    public function contentsToCopy(): array
    {
        return $this->contents()->orderBy('id')->get()->map(fn (RevisionContent $content): EmailContent => new EmailContent(
            locale: $content->locale,
            subject: $content->subject,
            html: $content->html,
            text: $content->text_generated ? null : $content->text,
            preheader: $content->preheader,
        ))->values()->all();
    }
}
