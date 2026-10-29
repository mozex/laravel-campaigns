<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\ClaimUploadAction;
use Mozex\Campaigns\Actions\CreateUploadAction;
use Mozex\Campaigns\Actions\PruneUploadsAction;
use Mozex\Campaigns\Actions\ReceiveUploadAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Models\Upload;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * An upload whose file has arrived.
 */
function receivedUpload(UploadPurpose $purpose = UploadPurpose::Email, string $body = '<p>Hello</p>'): Upload
{
    $upload = app(CreateUploadAction::class)->execute($purpose);

    return app(ReceiveUploadAction::class)->execute($upload->uuid, $body);
}

/**
 * The code and message a call refuses with.
 *
 * @return array{0: int, 1: string}
 */
function refusal(Closure $call): array
{
    try {
        $call();
    } catch (CampaignsException $exception) {
        return [$exception->getCode(), $exception->getMessage()];
    }

    throw new RuntimeException('The call was not refused.');
}

describe('an upload link', function (): void {
    it('takes one file, from a stream or a string, before it expires', function (): void {
        $this->freezeSecond();

        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email, 'laptop');

        expect($upload->expires_at->equalTo(now()->addMinutes(15)))->toBeTrue();

        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, str_repeat('a', 200_000));
        rewind($stream);

        $received = app(ReceiveUploadAction::class)->execute(strtoupper($upload->uuid), $stream, 'application/zip');

        expect($received)
            ->disk->toBe('local')
            ->size->toBe(200_000)
            ->mime->toBe('application/zip')
            ->author->toBe('laptop')
            ->and($received->received_at->equalTo(now()))->toBeTrue()
            ->and($received->expires_at->equalTo(now()->addHours(24)))->toBeTrue()
            ->and($received->path)->toStartWith("campaigns/uploads/{$upload->uuid}-")
            ->and($received->contents())->toBe(str_repeat('a', 200_000))
            ->and(refusal(fn () => app(ReceiveUploadAction::class)->execute($upload->uuid, 'again')))
            ->toBe([409, 'The upload can\'t be used: a file already arrived through it. Ask for a new link.'])
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('refuses a file over the limit while it arrives, and stores nothing', function (): void {
        config()->set('campaigns.uploads.max_size', 100_000);
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);

        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, str_repeat('a', 100_001));
        rewind($stream);

        expect(refusal(fn () => app(ReceiveUploadAction::class)->execute($upload->uuid, $stream)))
            ->toBe([413, 'The file is over the upload limit of 0.1 MB.'])
            ->and(refusal(fn () => app(ReceiveUploadAction::class)->execute($upload->uuid, str_repeat('a', 100_001)))[0])->toBe(413)
            ->and(refusal(fn () => app(ReceiveUploadAction::class)->execute($upload->uuid, ''))[0])->toBe(400)
            ->and($upload->fresh()->received_at)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(app(ReceiveUploadAction::class)->execute($upload->uuid, str_repeat('a', 100_000))->size)->toBe(100_000);
    });

    it('lets one of two requests racing on a link keep its file, the other deleting its own', function (): void {
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);

        // The other request lands while this one is still storing its file.
        $racing = new class extends ReceiveUploadAction
        {
            public string $uuid = '';

            protected function buffer(mixed $body): array
            {
                app(ReceiveUploadAction::class)->execute($this->uuid, 'the other request');

                return parent::buffer($body);
            }
        };
        $racing->uuid = $upload->uuid;

        expect(refusal(fn () => $racing->execute($upload->uuid, 'this request'))[0])->toBe(409)
            ->and($upload->fresh()->contents())->toBe('the other request')
            ->and(Storage::disk('local')->allFiles())->toBe([$upload->fresh()->path]);
    });

    it('refuses an unknown or expired link', function (): void {
        $upload = app(CreateUploadAction::class)->execute(UploadPurpose::Email);
        $this->travel(16)->minutes();

        expect(refusal(fn () => app(ReceiveUploadAction::class)->execute($upload->uuid, 'late'))[0])->toBe(410)
            ->and(refusal(fn () => app(ReceiveUploadAction::class)->execute((string) Str::uuid(), 'x'))[0])->toBe(404)
            ->and(refusal(fn () => app(ReceiveUploadAction::class)->execute('not-a-uuid', 'x'))[0])->toBe(404)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('claiming an upload', function (): void {
    it('hands its file to one tool, once', function (string $driver): void {
        useDatabase($driver);
        Storage::fake('local');

        $upload = receivedUpload();

        $claimed = app(ClaimUploadAction::class)->execute($upload->uuid, UploadPurpose::Email);

        expect($claimed->claimed_at)->not->toBeNull()
            ->and($claimed->contents())->toBe('<p>Hello</p>')
            ->and(refusal(fn () => app(ClaimUploadAction::class)->execute($upload->uuid, UploadPurpose::Email)))
            ->toBe([409, 'The upload can\'t be used: it was already used. Ask for a new link.']);
    })->with('databases');

    it('names why an upload can\'t be claimed', function (): void {
        $waiting = app(CreateUploadAction::class)->execute(UploadPurpose::Email);
        $image = receivedUpload(UploadPurpose::Image);
        $old = receivedUpload();

        $this->travel(25)->hours();

        expect(refusal(fn () => app(ClaimUploadAction::class)->execute($image->uuid, UploadPurpose::Email)))
            ->toBe([422, 'That upload is for an image, not an email.'])
            ->and(refusal(fn () => app(ClaimUploadAction::class)->execute($old->uuid, UploadPurpose::Email))[0])->toBe(410)
            ->and(refusal(fn () => app(ClaimUploadAction::class)->execute($waiting->uuid, UploadPurpose::Email))[0])->toBe(410);

        $fresh = app(CreateUploadAction::class)->execute(UploadPurpose::Email);

        expect(refusal(fn () => app(ClaimUploadAction::class)->execute($fresh->uuid, UploadPurpose::Email)))
            ->toBe([409, 'The upload can\'t be used: no file has arrived through it yet. Ask for a new link.']);
    });
});

it('prunes expired uploads with their files, and old files no upload names', function (): void {
    $kept = receivedUpload();
    $expired = receivedUpload();
    $unused = app(CreateUploadAction::class)->execute(UploadPurpose::Contacts);

    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    $unused->forceFill(['expires_at' => now()->subMinute()])->save();

    $failed = 'campaigns/uploads/'.Str::uuid().'-'.Str::random(16);
    $writing = 'campaigns/uploads/'.Str::uuid().'-'.Str::random(16);
    $apps = 'campaigns/uploads/the-apps-own-file.txt';

    foreach ([$failed, $writing, $apps] as $file) {
        Storage::disk('local')->put($file, 'x');
    }

    foreach ([$failed, $apps] as $file) {
        touch(Storage::disk('local')->path($file), now()->subHours(25)->getTimestamp());
    }

    expect(app(PruneUploadsAction::class)->execute())->toBe(2)
        ->and(Upload::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(Storage::disk('local')->allFiles())->toEqualCanonicalizing([$kept->path, $writing, $apps]);
});
