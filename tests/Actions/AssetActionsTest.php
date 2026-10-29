<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\AddImageAction;
use Mozex\Campaigns\Actions\AddImageFromUrlAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Asset;

beforeEach(function (): void {
    Storage::fake('public', ['url' => 'https://cdn.example.com/storage']);
    Http::preventStrayRequests();
    resolveHosts(['images.example.com' => '93.184.216.34']);
});

describe('adding an image', function (): void {
    it('stores each image type by its content, once however often it is added', function (string $type, string $mime, string $extension): void {
        $bytes = testImage($type);
        $path = 'campaigns/assets/'.hash('sha256', $bytes).".{$extension}";

        $asset = app(AddImageAction::class)->execute($bytes, 'Hero image.bin', 'laptop');
        $again = app(AddImageAction::class)->execute($bytes, 'another name');

        expect($asset->fresh())
            ->disk->toBe('public')
            ->path->toBe($path)
            ->url->toBe("https://cdn.example.com/storage/{$path}")
            ->name->toBe('Hero image.bin')
            ->mime->toBe($mime)
            ->size->toBe(strlen($bytes))
            ->width->toBe(2)
            ->height->toBe(1)
            ->author->toBe('laptop')
            ->and($again->id)->toBe($asset->id)
            ->and(Asset::query()->count())->toBe(1)
            ->and(Storage::disk('public')->get($path))->toBe($bytes);
    })->with([
        ['png', 'image/png', 'png'],
        ['gif', 'image/gif', 'gif'],
        ['webp', 'image/webp', 'webp'],
        ['jpeg', 'image/jpeg', 'jpg'],
    ]);

    it('refuses anything that isn\'t a JPEG, PNG, GIF or WebP within the limit', function (string $bytes, string $reason): void {
        config()->set('campaigns.assets.max_size', 200);

        expect(fn () => app(AddImageAction::class)->execute($bytes, 'logo.png'))
            ->toThrow(CampaignsException::class, $reason)
            ->and(Asset::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    })->with([
        'an SVG named like a PNG' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'is not a JPEG, PNG, GIF or WebP image'],
        'text' => ['hello', 'is not a JPEG, PNG, GIF or WebP image'],
        'nothing' => ['', 'is empty'],
        'an image over the limit' => [testImage('png').str_repeat("\0", 200), 'is over'],
    ]);

    it('refuses a disk whose addresses aren\'t https before storing anything', function (): void {
        Storage::fake('public', ['url' => 'http://cdn.example.com/storage']);

        expect(fn () => app(AddImageAction::class)->execute(testImage(), 'logo.png'))
            ->toThrow(CampaignsException::class, 'doesn\'t give https addresses')
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });
});

describe('adding an image from an address', function (): void {
    it('fetches it through the guard and names it after the address', function (): void {
        Http::fake(['https://images.example.com/*' => Http::response(testImage('gif'))]);

        $asset = app(AddImageFromUrlAction::class)->execute('https://images.example.com/a/My%20Logo.gif?v=2');

        expect($asset)
            ->name->toBe('My Logo.gif')
            ->mime->toBe('image/gif');
    });

    it('refuses an address on http, a private network, or one that fails', function (string $url, string $reason): void {
        Http::fake([
            'https://images.example.com/missing.png' => Http::response('', 404),
            'https://images.example.com/moved.png' => Http::response('', 302, ['Location' => 'https://10.0.0.5/logo.png']),
        ]);

        expect(fn () => app(AddImageFromUrlAction::class)->execute($url))
            ->toThrow(CampaignsException::class, $reason)
            ->and(Asset::query()->count())->toBe(0);
    })->with([
        ['http://images.example.com/logo.png', 'is not on https'],
        ['https://images.example.com/moved.png', 'private or reserved network'],
        ['https://images.example.com/missing.png', 'answered 404'],
    ]);
});
