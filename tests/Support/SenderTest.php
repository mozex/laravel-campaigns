<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Support\Sender;

beforeEach(function (): void {
    config()->set('campaigns.mail.mailer', 'campaigns');
    config()->set('mail.from', ['address' => null, 'name' => null]);
});

it('reads the sender from the package, then the campaigns mailer, then the app', function (): void {
    config()->set('mail.from', ['address' => 'hello@example.com', 'name' => 'Example']);

    expect(Sender::address())->toBe('hello@example.com')
        ->and(Sender::name())->toBe('Example')
        ->and(Sender::domain())->toBe('example.com');

    config()->set('mail.mailers.campaigns.from', ['address' => 'team@News.Example.com', 'name' => 'News']);

    expect(Sender::address())->toBe('team@News.Example.com')
        ->and(Sender::domain())->toBe('news.example.com');

    config()->set('campaigns.mail.from', ['address' => 'editor@letters.example.com', 'name' => 'Editor']);

    expect(Sender::address())->toBe('editor@letters.example.com')
        ->and(Sender::name())->toBe('Editor')
        ->and(Sender::domain())->toBe('letters.example.com');
});

it('takes the configured domain over the sender\'s', function (): void {
    config()->set('campaigns.mail.from.address', 'team@example.com');
    config()->set('campaigns.mail.domain', ' News.Example.com ');
    config()->set('campaigns.mail.reply_to', ' help@example.com ');

    expect(Sender::domain())->toBe('news.example.com')
        ->and(Sender::replyTo())->toBe('help@example.com')
        ->and(Sender::isOnDomain('team@NEWS.example.com'))->toBeTrue()
        ->and(Sender::isOnDomain('team@example.com'))->toBeFalse()
        ->and(Sender::isOnDomain('team@sub.news.example.com'))->toBeFalse()
        ->and(Sender::isOnDomain('not-an-address'))->toBeFalse();
});

it('says what to configure when nothing names a sending domain', function (): void {
    expect(Sender::address())->toBeNull()
        ->and(Sender::replyTo())->toBeNull()
        ->and(fn () => Sender::domain())->toThrow(CampaignsException::class, 'No sending domain is configured');
});
