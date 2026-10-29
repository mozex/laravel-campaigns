<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mozex\Campaigns\Models\Upload;

/**
 * @extends Factory<Upload>
 */
class UploadFactory extends Factory
{
    protected $model = Upload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purpose' => 'email',
            'path' => null,
            'size' => null,
            'mime' => null,
            'author' => null,
            'expires_at' => now()->addMinutes(15),
            'received_at' => null,
            'claimed_at' => null,
        ];
    }
}
