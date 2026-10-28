<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Concerns\HasCampaignSubscriber;

/**
 * An app's account, standing in for a users table.
 *
 * @property int $id
 * @property ?string $email
 * @property ?string $name
 * @property ?string $locale
 * @property ?string $country
 * @property ?Carbon $banned_at
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Member extends Model
{
    use HasCampaignSubscriber;
    use SoftDeletes;

    protected $table = 'members';

    protected $guarded = [];

    /**
     * @return array{banned_at: 'datetime'}
     */
    protected function casts(): array
    {
        return ['banned_at' => 'datetime'];
    }
}
