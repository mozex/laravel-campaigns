<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, RevisionContent> $contents
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
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{revisable_id: 'integer', number: 'integer', check_results: 'array', checked_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'revisable_id' => 'integer',
            'number' => 'integer',
            'check_results' => 'array',
            'checked_at' => 'datetime',
        ];
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

    /**
     * The content for a recipient's locale, else the default locale's, which
     * every revision holds.
     */
    public function contentFor(?string $locale): RevisionContent
    {
        $contents = $this->contents;

        return $contents->firstWhere('locale', $locale)
            ?? $contents->firstWhere('locale', Locales::default())
            ?? $contents->sortBy('id')->firstOrFail();
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
