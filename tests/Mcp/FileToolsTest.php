<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\ReceiveUploadAction;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Mcp\Tools\AddImageTool;
use Mozex\Campaigns\Mcp\Tools\CreateUploadLinkTool;
use Mozex\Campaigns\Mcp\Tools\ListAssetsTool;
use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Models\Upload;
use Mozex\Campaigns\Reports\AssetSummary;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public', ['url' => 'https://cdn.example.com/storage']);
    resolveHosts(['images.example.com' => '93.184.216.34', 'inside.example.com' => '127.0.0.1']);
});

describe('upload links', function (): void {
    it('opens a link for each purpose, recording who asked, with a command that sends the file', function (Rights $rights, bool $person, string $author): void {
        // Under any PHP's post_max_size, which caps them too.
        config(['campaigns.uploads.max_size' => 1024 * 1024, 'campaigns.assets.max_size' => 512 * 1024]);
        $answers = [];

        foreach (['email', 'image', 'contacts'] as $purpose) {
            $answers[$purpose] = structuredContent(agent($rights, $person)->tool(CreateUploadLinkTool::class, ['purpose' => $purpose, 'file_name' => 'my files/news.zip'])->assertOk());
        }

        $upload = Upload::forId($answers['image']['upload_id']);

        expect(Upload::query()->pluck('purpose')->map->value->all())->toBe(['email', 'image', 'contacts'])
            ->and(Upload::query()->pluck('author')->unique()->all())->toBe([$author])
            ->and($answers['image'])
            ->purpose->toBe('image')
            ->method->toBe('PUT')
            ->expires_at->toBe($upload->expires_at->toIso8601String())
            ->max_file_size->toBe(512 * 1024)
            ->and($answers['email']['max_file_size'])->toBe(1024 * 1024)
            ->and($answers['image']['upload_url'])->toStartWith("http://localhost/mcp/campaigns/uploads/{$upload->uuid}?expires=")
            ->and($answers['image']['curl'])->toBe("curl --fail-with-body --upload-file 'my files/news.zip' '{$answers['image']['upload_url']}'");

        agent($rights, $person)->tool(CreateUploadLinkTool::class, ['purpose' => 'video'])->assertHasErrors();
        agent($rights, $person)->tool(CreateUploadLinkTool::class, ['purpose' => 'email', 'file_name' => "it's.zip"])->assertHasErrors(['file name']);
    })->with([
        'a token' => [Rights::Full, false, 'laptop'],
        'a person' => [Rights::Full, true, 'Ann Agent'],
    ]);

    it('reads PHP\'s post_max_size as Laravel does', function (string $value, int $bytes): void {
        expect((fn (): int => static::iniBytes($value))->call(new Upload))->toBe($bytes);
    })->with([
        ['8M', 8 * 1024 * 1024],
        ['1G', 1024 ** 3],
        ['512k', 512 * 1024],
        ['1048576', 1048576],
        ['0', 0],
    ]);
});

describe('adding images', function (): void {
    it('adds an image sent through an upload link once, then deletes the file', function (string $driver): void {
        useDatabase($driver);
        $link = structuredContent(agent()->tool(CreateUploadLinkTool::class, ['purpose' => 'image'])->assertOk());
        $upload = app(ReceiveUploadAction::class)->execute($link['upload_id'], testImage('png'));

        $added = structuredContent(agent()->tool(AddImageTool::class, ['upload_id' => $upload->uuid, 'name' => 'Logo'])->assertOk());

        expect($added['added'])->toBeTrue()
            ->and($added['asset'])->toMatchArray([
                'name' => 'Logo',
                'url' => 'https://cdn.example.com/storage/campaigns/assets/'.hash('sha256', testImage('png')).'.png',
                'mime' => 'image/png',
                'width' => 2,
                'height' => 1,
                'author' => 'laptop',
            ])
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(Asset::query()->count())->toBe(1);

        agent()->tool(AddImageTool::class, ['upload_id' => $upload->uuid])->assertHasErrors(['it was already used']);
    })->with('databases');

    it('refuses an upload of another purpose without using it up', function (): void {
        $link = structuredContent(agent()->tool(CreateUploadLinkTool::class, ['purpose' => 'email'])->assertOk());
        $upload = app(ReceiveUploadAction::class)->execute($link['upload_id'], testImage('png'));

        agent()->tool(AddImageTool::class, ['upload_id' => $upload->uuid])->assertHasErrors(['That upload is for an email, not an image.']);

        expect($upload->refresh()->claimed_at)->toBeNull();
    });

    it('adds an image sent inline, with or without a data URL\'s prefix, as one image', function (): void {
        $first = structuredContent(agent()->tool(AddImageTool::class, ['data' => base64_encode(testImage('gif'))])->assertOk());
        $again = structuredContent(agent(person: true)->tool(AddImageTool::class, ['data' => 'data:image/gif;base64,'.chunk_split(base64_encode(testImage('gif')), 8)])->assertOk());

        expect($first['added'])->toBeTrue()
            ->and($again['added'])->toBeFalse()
            ->and($again['asset']['id'])->toBe($first['asset']['id'])
            ->and($first['asset']['author'])->toBe('laptop');
    });

    it('refuses inline data that isn\'t an image, or is over the inline limit', function (string $data, string $error): void {
        agent()->tool(AddImageTool::class, ['data' => $data])->assertHasErrors([$error]);

        expect(Asset::query()->count())->toBe(0);
    })->with([
        'not base64' => ['%%%', 'The data isn\'t an image in base64.'],
        'an SVG' => [base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'JPEG, PNG, GIF or WebP'],
        'well over 4 MB' => [base64_encode(testImage('png').str_repeat('x', AddImageTool::INLINE_MAX)), 'The data is over the 4 MB'],
        'a byte over 4 MB' => [base64_encode(testImage('png').str_repeat('x', AddImageTool::INLINE_MAX - strlen(testImage('png')) + 1)), 'The data is over the 4 MB'],
    ]);

    it('adds an image from an https address', function (): void {
        Http::fake(['https://images.example.com/*' => Http::response(testImage('webp'))]);

        $added = structuredContent(agent()->tool(AddImageTool::class, ['url' => 'https://images.example.com/hero%20shot.webp'])->assertOk());

        expect($added['asset'])->name->toBe('hero shot.webp')->mime->toBe('image/webp');
    });

    it('refuses an address that leads into the server\'s own network', function (string $target): void {
        Http::fake(['https://images.example.com/moved.png' => Http::response('', 302, ['Location' => $target])]);

        agent()->tool(AddImageTool::class, ['url' => 'https://images.example.com/moved.png'])->assertHasErrors(['private or reserved network']);

        expect(Asset::query()->count())->toBe(0);
    })->with([
        'the loopback address' => 'https://127.0.0.1/logo.png',
        'the cloud metadata address' => 'https://169.254.169.254/latest/meta-data',
        'a name resolving to the loopback address' => 'https://inside.example.com/logo.png',
    ]);

    it('takes exactly one source', function (array $arguments): void {
        agent()->tool(AddImageTool::class, $arguments)->assertHasErrors();
    })->with([
        'none' => [[]],
        'two' => [['data' => base64_encode(testImage()), 'url' => 'https://images.example.com/a.png']],
        'all three' => [['upload_id' => 'x', 'data' => base64_encode(testImage()), 'url' => 'https://images.example.com/a.png']],
    ]);
});

describe('the asset library', function (): void {
    it('lists the images newest first and finds them by name or address', function (string $driver): void {
        useDatabase($driver);
        $logo = Asset::factory()->create(['name' => 'Logo.png', 'url' => 'https://cdn.example.com/storage/campaigns/assets/aaa.png']);
        $hero = Asset::factory()->create(['name' => 'Hero.jpg', 'url' => 'https://cdn.example.com/storage/campaigns/assets/bbb.jpg']);

        $all = structuredContent(agent(Rights::Read)->tool(ListAssetsTool::class)->assertOk());
        $byName = structuredContent(agent(Rights::Read)->tool(ListAssetsTool::class, ['search' => 'logo'])->assertOk());
        $byAddress = structuredContent(agent(Rights::Read)->tool(ListAssetsTool::class, ['search' => $hero->url])->assertOk());

        expect(array_column($all['assets'], 'id'))->toBe([$hero->uuid, $logo->uuid])
            ->and($all)->total->toBe(2)->pages->toBe(1)
            ->and(array_column($byName['assets'], 'id'))->toBe([$logo->uuid])
            ->and(array_column($byAddress['assets'], 'id'))->toBe([$hero->uuid]);
    })->with('databases');

    it('pages the images so the longest page stays under the limit', function (string $character): void {
        Asset::factory()->count(ListAssetsTool::PER_PAGE + 1)->create([
            'url' => 'https://cdn.example.com/'.str_repeat('a', 2024),
            'name' => mb_substr(str_repeat($character, 255), 0, 255),
            'author' => mb_substr(str_repeat($character, 255), 0, 255),
            'mime' => mb_substr(str_repeat($character, 127), 0, 127),
        ]);

        $first = structuredContent(agent(Rights::Read)->tool(ListAssetsTool::class)->assertOk());

        expect($first['assets'])->toHaveCount(ListAssetsTool::PER_PAGE)
            ->and($first['pages'])->toBe(2)
            ->and(mb_strlen($first['assets'][0]['name']))->toBe(AssetSummary::NAME_LENGTH + 3)
            ->and(json_encode($first))->not->toContain('\u0001')
            ->and(json_encode($first))->not->toContain(' ');
    })->with('costly characters');
});
