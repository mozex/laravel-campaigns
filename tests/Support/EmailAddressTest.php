<?php

declare(strict_types=1);

use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\EmailAddress;

/**
 * A 254-character address at a label length of 61, one longer at 62. A
 * domain label can't pass 63 characters, so the domain needs three.
 */
function longAddress(int $label): string
{
    return str_repeat('a', 64).'@'.str_repeat('b', $label).'.'.str_repeat('c', 61).'.'.str_repeat('d', 61).'.com';
}

it('normalizes an address, or refuses one that cannot be mailed', function (?string $input, ?string $expected): void {
    expect(EmailAddress::normalize($input))->toBe($expected);
})->with([
    'spaces and capitals' => ['  Jane.Doe@Example.COM ', 'jane.doe@example.com'],
    'letters outside ASCII' => ['José@example.com', 'josé@example.com'],
    'the longest allowed' => [longAddress(61), longAddress(61)],
    'one character too long' => [longAddress(62), null],
    'no @' => ['jane.example.com', null],
    'a space inside' => ['jane doe@example.com', null],
    'blank' => ['   ', null],
    'null' => [null, null],
]);

it('stores and finds every address cleaned, whichever code writes it', function (): void {
    $subscriber = Subscriber::factory()->create(['email' => '  Jane@Example.COM ']);

    expect($subscriber->fresh()?->email)->toBe('jane@example.com')
        ->and(Subscriber::findByEmail('JANE@example.com ')?->is($subscriber))->toBeTrue();
});
