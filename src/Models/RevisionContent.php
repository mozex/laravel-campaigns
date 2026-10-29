<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\RevisionContentFactory;

/**
 * A revision's email in one language. The plain text was written by the
 * author, or generated from the HTML when none was given (text_generated).
 *
 * @property int $id
 * @property int $revision_id
 * @property string $locale
 * @property string $subject
 * @property ?string $preheader
 * @property string $html
 * @property string $text
 * @property bool $text_generated
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(RevisionContentFactory::class)]
class RevisionContent extends Model
{
    /** @use HasFactory<RevisionContentFactory> */
    use HasFactory;

    protected $table = 'campaign_revision_contents';

    protected $fillable = [
        'revision_id',
        'locale',
        'subject',
        'preheader',
        'html',
        'text',
        'text_generated',
    ];

    /**
     * @return array{text_generated: 'boolean'}
     */
    protected function casts(): array
    {
        return [
            'text_generated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Revision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }
}
