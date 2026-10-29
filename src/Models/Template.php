<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Database\Factories\TemplateFactory;
use Mozex\Campaigns\Models\Concerns\HasRevisions;

/**
 * A reusable email kept on the app: its content lives in numbered revisions,
 * and a campaign made from it takes a copy, so editing the template never
 * changes a campaign. The package's own starter templates are files, not
 * rows (Templates\StarterTemplates).
 *
 * @property int $id
 * @property string $uuid
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property bool $is_default
 * @property ?Carbon $archived_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[UseFactory(TemplateFactory::class)]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    use HasRevisions;
    use HasUuids;

    protected $table = 'campaign_templates';

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_default',
        'archived_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array{is_default: 'boolean', archived_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public static function findByKey(string $key): ?self
    {
        return static::query()->where('key', $key)->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
