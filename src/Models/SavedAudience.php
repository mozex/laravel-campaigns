<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\SavedAudienceFactory;

/**
 * A named set of the built-in subscriber filters an agent saved, kept as
 * Audiences\SubscriberFilters::toArray() writes it.
 *
 * @property int $id
 * @property string $uuid
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property array<string, mixed> $filters
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(SavedAudienceFactory::class)]
class SavedAudience extends Model
{
    /** @use HasFactory<SavedAudienceFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'campaign_saved_audiences';

    protected $fillable = [
        'key',
        'name',
        'description',
        'filters',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{filters: 'array'}
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }
}
