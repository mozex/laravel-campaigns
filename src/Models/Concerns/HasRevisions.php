<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Mozex\Campaigns\Models\Revision;

/**
 * A template or a campaign, whose email is kept as numbered revisions. The
 * current one is the highest number.
 */
trait HasRevisions
{
    /** @return MorphMany<Revision, $this> */
    public function revisions(): MorphMany
    {
        return $this->morphMany(Revision::class, 'revisable');
    }

    /** @return MorphOne<Revision, $this> */
    public function currentRevision(): MorphOne
    {
        return $this->morphOne(Revision::class, 'revisable')->ofMany('number', 'max');
    }
}
